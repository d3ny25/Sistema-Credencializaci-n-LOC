using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;

namespace AgenteEstacion
{
    /// <summary>
    /// Agente local de la estación biométrica. Escucha solo en 127.0.0.1, atiende únicamente al origen web de Laravel
    /// configurado y exige un ticket de un solo uso emitido por Laravel para abrir cada sesión de cámara.
    /// No guarda imágenes ni credenciales de Laravel: el navegador sube la fotografía con la sesión del operador.
    /// </summary>
    public static class Programa
    {
        public const string Version = "0.1.0";
        private const int InactividadSegundos = 60;
        private const int MaxCabeceras = 16 * 1024;

        private static Configuracion config;
        private static ICamara camara;
        private static VerificadorTicket verificador;
        private static readonly object candadoCamara = new object();
        private static string sesionId;
        private static long sesionElemento;
        private static DateTime sesionUltimoUso;
        private static bool vistaPreviaActiva;

        // Lector de huellas: estado, candado y sesión propios, independientes de la cámara.
        private static readonly string[] CategoriasCamara = { "foto" };
        private static readonly string[] CategoriasLector = { "pulgar_derecho", "pulgar_izquierdo" };
        private static ILectorHuella lector;
        private static readonly object candadoLector = new object();
        private static string lectorSesionId;
        private static DateTime lectorUltimoUso;

        // Monitor de impresión (solo lectura de la cola de Windows): candado, hilo y sesión propios.
        private static readonly string[] CategoriasImpresion = { "impresion" };
        private const string PrefijoDocumento = "LOC139-";
        private static IMonitorImpresion monitor;
        private static readonly HiloSdk hiloImpresion = new HiloSdk("Monitor de impresión");
        private static readonly object candadoImpresion = new object();
        private static string impresionSesionId;
        private static string impresionDocumento;
        private static bool impresionTrabajoVisto;
        private static DateTime impresionUltimoUso;

        public static int Main(string[] args)
        {
            Console.OutputEncoding = Encoding.UTF8;
            try
            {
                config = Configuracion.Cargar(args);
            }
            catch (Exception e)
            {
                Console.Error.WriteLine("Configuración inválida: " + e.Message);
                return 2;
            }

            string modoSimulado = Configuracion.ModoSimulado(args, "--simulado", "conectada");
            string motivoCamara = null;
            camara = modoSimulado != null ? (ICamara)new CamaraSimulada(modoSimulado) : CamaraEdsdk.Crear(config.RutaEdsdk, out motivoCamara);
            string modoLector = Configuracion.ModoSimulado(args, "--simulado-lector", "conectado");
            lector = modoLector != null ? new LectorSimulado(modoLector) : LectorCrossMatchSdk.Crear(config.RutaSdkCrossMatch);
            string modoImpresora = Configuracion.ModoSimulado(args, "--simulado-impresora", "lista");
            monitor = modoImpresora != null ? (IMonitorImpresion)new MonitorImpresionSimulado(modoImpresora) : new MonitorImpresionWindows(config.ColaImpresora, config.PatronPnpImpresora);
            verificador = new VerificadorTicket(config.SecretoEstacion);
            // Al terminar el proceso el lector se libera (USB_Dispose) aunque haya una sesión abierta.
            AppDomain.CurrentDomain.ProcessExit += (s, e) => { lector.Cerrar(); camara.DetenerVistaPrevia(); };
            Console.CancelKeyPress += (s, e) => { lector.Cerrar(); camara.DetenerVistaPrevia(); };

            var escucha = new TcpListener(IPAddress.Loopback, config.Puerto);
            escucha.Start();
            Console.WriteLine("Agente de estación " + Version + " escuchando en http://127.0.0.1:" + config.Puerto + " para " + config.OrigenPermitido);
            if (camara.EsSimulacion) Console.WriteLine("*** MODO SIMULACIÓN (" + modoSimulado + "): ninguna imagen proviene de una cámara física ***");
            Console.WriteLine("Cámara: " + camara.Nombre + (motivoCamara != null ? " (" + motivoCamara + ")" : ""));
            if (lector.EsSimulacion) Console.WriteLine("*** LECTOR EN MODO SIMULACIÓN (" + modoLector + "): ninguna huella proviene de un lector físico ***");
            Console.WriteLine("Lector de huellas: " + lector.Nombre + " (" + lector.Estado() + ")");
            if (monitor.EsSimulacion) Console.WriteLine("*** IMPRESORA EN MODO SIMULACIÓN (" + modoImpresora + "): ninguna cola real se consulta ***");
            Console.WriteLine("Monitor de impresión (solo lectura): " + monitor.Nombre + " · cola \"" + config.ColaImpresora + "\"");

            new Thread(VigilarInactividad) { IsBackground = true }.Start();
            while (true)
            {
                TcpClient cliente = escucha.AcceptTcpClient();
                ThreadPool.QueueUserWorkItem(_ => Atender(cliente));
            }
        }

        private static void Atender(TcpClient cliente)
        {
            using (cliente)
            {
                cliente.ReceiveTimeout = 5000;
                cliente.SendTimeout = 10000;
                NetworkStream flujo = cliente.GetStream();
                Peticion peticion = Peticion.Leer(flujo, MaxCabeceras);
                if (peticion == null) return;

                Respuesta respuesta;
                try
                {
                    respuesta = Enrutar(peticion);
                }
                catch (CamaraException e)
                {
                    respuesta = Respuesta.Error(e.Http, e.Codigo, e.Message);
                }
                catch (Exception)
                {
                    respuesta = Respuesta.Error(500, "error_interno", "Error interno del agente.");
                }

                string origen = peticion.Cabecera("Origin");
                if (origen == config.OrigenPermitido) respuesta.Cors(origen);
                respuesta.Escribir(flujo);
            }
        }

        private static Respuesta Enrutar(Peticion p)
        {
            // Defensa ante DNS rebinding: solo se aceptan los nombres de bucle local con el puerto del agente.
            string host = p.Cabecera("Host");
            if (host != "127.0.0.1:" + config.Puerto && host != "localhost:" + config.Puerto)
            {
                return Respuesta.Error(403, "host_no_permitido", "Host no permitido.");
            }
            if (p.Cabecera("Origin") != config.OrigenPermitido)
            {
                return Respuesta.Error(403, "origen_no_permitido", "Origen no permitido.");
            }

            if (p.Metodo == "OPTIONS") return Respuesta.Preflight(p.Cabecera("Access-Control-Request-Private-Network") == "true");

            string ruta = p.Ruta;
            if (p.Metodo == "GET" && ruta == "/v1/estado") return Estado();
            if (ruta.StartsWith("/v1/huella/", StringComparison.Ordinal)) return EnrutarLector(p, ruta.Substring("/v1/huella".Length));
            if (ruta.StartsWith("/v1/impresion/", StringComparison.Ordinal)) return EnrutarImpresion(p, ruta.Substring("/v1/impresion".Length));
            if (p.Metodo == "POST" && ruta == "/v1/sesiones") return AbrirSesion(p);

            lock (candadoCamara)
            {
                ExigirSesion(p);
                if (p.Metodo == "POST" && ruta == "/v1/vista-previa")
                {
                    camara.IniciarVistaPrevia();
                    vistaPreviaActiva = true;
                    return Respuesta.Json(200, Datos("vista_previa", true));
                }
                if (p.Metodo == "GET" && ruta == "/v1/fotograma") return Respuesta.Jpeg(camara.Fotograma(), null, camara.EsSimulacion);
                if (p.Metodo == "POST" && ruta == "/v1/captura")
                {
                    string origen;
                    byte[] foto = camara.Capturar(out origen);
                    return Respuesta.Jpeg(foto, origen, camara.EsSimulacion);
                }
                if (p.Metodo == "DELETE" && ruta == "/v1/sesiones")
                {
                    CerrarSesion();
                    return Respuesta.Json(200, Datos("cerrada", true));
                }
            }

            return Respuesta.Error(404, "no_encontrado", "Recurso no encontrado.");
        }

        private static Respuesta Estado()
        {
            string estado;
            string modelo;
            lock (candadoCamara)
            {
                estado = camara.Estado();
                modelo = camara.Modelo();
            }
            var datos = Datos("agente", "ok");
            datos["version"] = Version;
            datos["adaptador"] = camara.Nombre;
            datos["simulacion"] = camara.EsSimulacion;
            datos["camara"] = new Dictionary<string, object> { { "estado", estado }, { "modelo", modelo } };
            // Último estado conocido del lector, sin sondearlo: verificar la cámara nunca inicializa el lector.
            datos["lector"] = DatosLector(lector.Estado());
            return Respuesta.Json(200, datos);
        }

        private static Dictionary<string, object> DatosLector(string estado)
        {
            return new Dictionary<string, object>
            {
                { "estado", estado }, { "modelo", lector.Modelo() }, { "adaptador", lector.Nombre },
                { "simulacion", lector.EsSimulacion }, { "detalle", lector.Detalle() },
            };
        }

        private static Respuesta EnrutarLector(Peticion p, string ruta)
        {
            lock (candadoLector)
            {
                if (p.Metodo == "GET" && ruta == "/estado")
                {
                    // Con una sesión abierta no se reinicializa el lector: se informa su estado actual.
                    return Respuesta.Json(200, DatosLector(lectorSesionId != null ? lector.Estado() : lector.Verificar()));
                }
                if (p.Metodo == "POST" && ruta == "/sesiones")
                {
                    string autorizacion = p.Cabecera("Authorization") ?? "";
                    if (!autorizacion.StartsWith("Bearer ", StringComparison.Ordinal))
                    {
                        return Respuesta.Error(401, "ticket_invalido", "Falta el ticket de captura.");
                    }
                    TicketValido ticket = verificador.Consumir(autorizacion.Substring(7).Trim(), AhoraUnix(), CategoriasLector);
                    CerrarSesionLector();
                    lector.Abrir();
                    lectorSesionId = Aleatorio();
                    lectorUltimoUso = DateTime.UtcNow;
                    var datos = Datos("sesion", lectorSesionId);
                    datos["elemento_id"] = ticket.Elemento;
                    datos["categoria"] = ticket.Categoria;
                    datos["inactividad_segundos"] = InactividadSegundos;
                    datos["simulacion"] = lector.EsSimulacion;
                    return Respuesta.Json(201, datos);
                }

                string recibida = p.Cabecera("X-Sesion");
                if (lectorSesionId == null || recibida == null || recibida != lectorSesionId)
                {
                    return Respuesta.Error(401, "sesion_invalida", "La sesión de captura no es válida o expiró.");
                }
                lectorUltimoUso = DateTime.UtcNow;

                if (p.Metodo == "POST" && ruta == "/vista-previa")
                {
                    lector.IniciarVistaPrevia();
                    return Respuesta.Json(200, Datos("vista_previa", true));
                }
                if (p.Metodo == "GET" && ruta == "/fotograma") return Respuesta.Imagen(lector.Fotograma(), "image/png", null, lector.EsSimulacion);
                if (p.Metodo == "POST" && ruta == "/captura") return Respuesta.Imagen(lector.Capturar(), "image/png", "lector", lector.EsSimulacion);
                if (p.Metodo == "DELETE" && ruta == "/sesiones")
                {
                    CerrarSesionLector();
                    return Respuesta.Json(200, Datos("cerrada", true));
                }
            }
            return Respuesta.Error(404, "no_encontrado", "Recurso no encontrado.");
        }

        private static void CerrarSesionLector()
        {
            lectorSesionId = null;
            lector.Cerrar();
        }

        /// <summary>
        /// Impresión: el agente NO recibe contenido, rutas ni comandos. Solo informa el estado de la cola configurada y
        /// busca el trabajo cuyo documento lleva el identificador del ticket que Laravel emitió para esa preparación.
        /// </summary>
        private static Respuesta EnrutarImpresion(Peticion p, string ruta)
        {
            lock (candadoImpresion)
            {
                if (p.Metodo == "GET" && ruta == "/estado")
                {
                    EstadoCola cola = hiloImpresion.Ejecutar(() => monitor.Estado(), 15000);
                    var datos = Datos("estado", cola.Estado);
                    datos["cola"] = cola.Cola;
                    datos["controlador"] = cola.Controlador;
                    datos["puerto"] = cola.Puerto;
                    datos["trabajos_en_cola"] = cola.Trabajos;
                    datos["dispositivo_presente"] = cola.DispositivoPresente;
                    datos["alertas"] = cola.Alertas;
                    datos["adaptador"] = monitor.Nombre;
                    datos["simulacion"] = monitor.EsSimulacion;
                    return Respuesta.Json(200, datos);
                }
                if (p.Metodo == "POST" && ruta == "/sesiones")
                {
                    string autorizacion = p.Cabecera("Authorization") ?? "";
                    if (!autorizacion.StartsWith("Bearer ", StringComparison.Ordinal))
                    {
                        return Respuesta.Error(401, "ticket_invalido", "Falta el ticket de impresión.");
                    }
                    TicketValido ticket = verificador.Consumir(autorizacion.Substring(7).Trim(), AhoraUnix(), CategoriasImpresion);
                    impresionSesionId = Aleatorio();
                    impresionDocumento = PrefijoDocumento + ticket.Nonce;
                    impresionTrabajoVisto = false;
                    impresionUltimoUso = DateTime.UtcNow;
                    var datos = Datos("sesion", impresionSesionId);
                    datos["documento"] = impresionDocumento;
                    datos["elemento_id"] = ticket.Elemento;
                    datos["inactividad_segundos"] = InactividadSegundos * 5;
                    datos["simulacion"] = monitor.EsSimulacion;
                    return Respuesta.Json(201, datos);
                }

                string recibida = p.Cabecera("X-Sesion");
                if (impresionSesionId == null || recibida == null || recibida != impresionSesionId)
                {
                    return Respuesta.Error(401, "sesion_invalida", "La sesión de seguimiento no es válida o expiró.");
                }
                impresionUltimoUso = DateTime.UtcNow;

                if (p.Metodo == "GET" && ruta == "/trabajo")
                {
                    string documento = impresionDocumento;
                    InfoTrabajo trabajo = hiloImpresion.Ejecutar(() => monitor.BuscarTrabajo(documento), 15000);
                    string estado = trabajo.Estado;
                    // Windows retira los trabajos impresos: si ya se había visto y desapareció, solo se sabe que salió de la cola.
                    if (estado == EstadoTrabajo.NoEncontrado && impresionTrabajoVisto) estado = EstadoTrabajo.SalioDeCola;
                    if (estado != EstadoTrabajo.NoEncontrado && estado != EstadoTrabajo.SalioDeCola) impresionTrabajoVisto = true;
                    var datos = Datos("estado", estado);
                    datos["job_id"] = trabajo.JobId;
                    datos["cola"] = trabajo.Cola;
                    datos["alertas"] = trabajo.Alertas;
                    datos["documento"] = documento;
                    datos["simulacion"] = monitor.EsSimulacion;
                    datos["nota"] = "Estado de la cola de Windows: no confirma que la tarjeta física se imprimió correctamente.";
                    return Respuesta.Json(200, datos);
                }
                if (p.Metodo == "DELETE" && ruta == "/sesiones")
                {
                    impresionSesionId = null;
                    impresionDocumento = null;
                    return Respuesta.Json(200, Datos("cerrada", true));
                }
            }
            return Respuesta.Error(404, "no_encontrado", "Recurso no encontrado.");
        }

        private static Respuesta AbrirSesion(Peticion p)
        {
            string autorizacion = p.Cabecera("Authorization") ?? "";
            if (!autorizacion.StartsWith("Bearer ", StringComparison.Ordinal))
            {
                return Respuesta.Error(401, "ticket_invalido", "Falta el ticket de captura.");
            }
            long elemento = verificador.Consumir(autorizacion.Substring(7).Trim(), AhoraUnix(), CategoriasCamara).Elemento;

            lock (candadoCamara)
            {
                // La cámara es exclusiva: una sesión nueva cierra la anterior y su vista previa.
                CerrarSesion();
                sesionId = Aleatorio();
                sesionElemento = elemento;
                sesionUltimoUso = DateTime.UtcNow;
            }
            var datos = Datos("sesion", sesionId);
            datos["elemento_id"] = elemento;
            datos["inactividad_segundos"] = InactividadSegundos;
            datos["simulacion"] = camara.EsSimulacion;
            return Respuesta.Json(201, datos);
        }

        private static void ExigirSesion(Peticion p)
        {
            string recibida = p.Cabecera("X-Sesion");
            if (sesionId == null || recibida == null || recibida != sesionId)
            {
                throw new CamaraException("sesion_invalida", 401, "La sesión de captura no es válida o expiró.");
            }
            sesionUltimoUso = DateTime.UtcNow;
        }

        private static void CerrarSesion()
        {
            if (vistaPreviaActiva)
            {
                try { camara.DetenerVistaPrevia(); } catch (Exception) { }
                vistaPreviaActiva = false;
            }
            sesionId = null;
            sesionElemento = 0;
        }

        // Si el navegador se cierra a media captura, la vista previa se detiene sola.
        private static void VigilarInactividad()
        {
            while (true)
            {
                Thread.Sleep(5000);
                lock (candadoCamara)
                {
                    if (sesionId != null && (DateTime.UtcNow - sesionUltimoUso).TotalSeconds > InactividadSegundos) CerrarSesion();
                }
                lock (candadoLector)
                {
                    if (lectorSesionId != null && (DateTime.UtcNow - lectorUltimoUso).TotalSeconds > InactividadSegundos) CerrarSesionLector();
                }
                lock (candadoImpresion)
                {
                    // Una impresión de tarjeta puede tardar minutos: el seguimiento caduca a los 5 minutos sin consultas.
                    if (impresionSesionId != null && (DateTime.UtcNow - impresionUltimoUso).TotalSeconds > InactividadSegundos * 5)
                    {
                        impresionSesionId = null;
                        impresionDocumento = null;
                    }
                }
            }
        }

        private static Dictionary<string, object> Datos(string clave, object valor)
        {
            return new Dictionary<string, object> { { clave, valor } };
        }

        private static long AhoraUnix()
        {
            return (long)(DateTime.UtcNow - new DateTime(1970, 1, 1, 0, 0, 0, DateTimeKind.Utc)).TotalSeconds;
        }

        private static string Aleatorio()
        {
            byte[] bytes = new byte[16];
            using (var rng = RandomNumberGenerator.Create()) rng.GetBytes(bytes);
            return BitConverter.ToString(bytes).Replace("-", "").ToLowerInvariant();
        }
    }

    public class Configuracion
    {
        public int Puerto;
        public string OrigenPermitido;
        public string SecretoEstacion;
        /// <summary>Carpeta con USB4XX.dll y sus dependencias (opcional; sin ella el lector informa "sdk_no_instalado").</summary>
        public string RutaSdkCrossMatch;
        /// <summary>Carpeta con EDSDK.dll y EdsImage.dll de Canon (opcional; sin ella la cámara informa "sdk_no_instalado").</summary>
        public string RutaEdsdk;
        /// <summary>Nombre exacto de la cola de Windows de la impresora de credenciales.</summary>
        public string ColaImpresora;
        /// <summary>Fragmento del identificador PnP con el que se reconoce la impresora conectada.</summary>
        public string PatronPnpImpresora;

        public static Configuracion Cargar(string[] args)
        {
            string ruta = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "agente.config.json");
            for (int i = 0; i < args.Length - 1; i++) if (args[i] == "--config") ruta = args[i + 1];

            var datos = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(File.ReadAllText(ruta, Encoding.UTF8));
            var c = new Configuracion
            {
                Puerto = Convert.ToInt32(datos["puerto"]),
                OrigenPermitido = ((string)datos["origenPermitido"]).TrimEnd('/'),
                SecretoEstacion = (string)datos["secretoEstacion"],
                RutaSdkCrossMatch = datos.ContainsKey("rutaSdkCrossMatch") ? datos["rutaSdkCrossMatch"] as string : null,
                RutaEdsdk = datos.ContainsKey("rutaEdsdk") ? datos["rutaEdsdk"] as string : null,
                // Valores comprobados en la estación (Get-Printer / Get-PnpDevice, 15-11-2026).
                ColaImpresora = datos.ContainsKey("colaImpresora") ? datos["colaImpresora"] as string : "Zebra ZXP Series 7 USB Card Printer",
                PatronPnpImpresora = datos.ContainsKey("patronPnpImpresora") ? datos["patronPnpImpresora"] as string : "ZXP_SERIES_7",
            };
            if (c.Puerto < 1024 || c.Puerto > 65535) throw new ArgumentException("puerto fuera de rango");
            if (!c.OrigenPermitido.StartsWith("http://", StringComparison.Ordinal) && !c.OrigenPermitido.StartsWith("https://", StringComparison.Ordinal))
            {
                throw new ArgumentException("origenPermitido debe ser un origen http(s) exacto");
            }
            if (string.IsNullOrEmpty(c.SecretoEstacion) || c.SecretoEstacion.Length < 32) throw new ArgumentException("secretoEstacion debe tener al menos 32 caracteres");
            return c;
        }

        /// <summary>Bandera[=modo] (--simulado, --simulado-lector); null si no se pidió simulación.</summary>
        public static string ModoSimulado(string[] args, string bandera, string porDefecto)
        {
            foreach (string a in args)
            {
                if (a == bandera) return porDefecto;
                if (a.StartsWith(bandera + "=", StringComparison.Ordinal)) return a.Substring(bandera.Length + 1);
            }
            return null;
        }
    }

    public class Peticion
    {
        public string Metodo;
        public string Ruta;
        private readonly Dictionary<string, string> cabeceras = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

        public string Cabecera(string nombre)
        {
            string valor;
            return cabeceras.TryGetValue(nombre, out valor) ? valor : null;
        }

        /// <summary>Lee línea de petición y cabeceras (sin cuerpo: ninguna ruta lo necesita). Null si es inválida.</summary>
        public static Peticion Leer(Stream flujo, int maximo)
        {
            var bytes = new List<byte>();
            try
            {
                while (bytes.Count < maximo)
                {
                    int b = flujo.ReadByte();
                    if (b < 0) return null;
                    bytes.Add((byte)b);
                    int n = bytes.Count;
                    if (n >= 4 && bytes[n - 4] == 13 && bytes[n - 3] == 10 && bytes[n - 2] == 13 && bytes[n - 1] == 10) break;
                }
            }
            catch (IOException)
            {
                return null;
            }

            string[] lineas = Encoding.ASCII.GetString(bytes.ToArray()).Split(new[] { "\r\n" }, StringSplitOptions.None);
            string[] primera = lineas[0].Split(' ');
            if (primera.Length != 3 || !primera[2].StartsWith("HTTP/1.", StringComparison.Ordinal)) return null;

            var p = new Peticion { Metodo = primera[0], Ruta = primera[1].Split('?')[0] };
            for (int i = 1; i < lineas.Length; i++)
            {
                int dos = lineas[i].IndexOf(':');
                if (dos > 0) p.cabeceras[lineas[i].Substring(0, dos).Trim()] = lineas[i].Substring(dos + 1).Trim();
            }
            return p;
        }
    }

    public class Respuesta
    {
        private int estado;
        private string tipo;
        private byte[] cuerpo;
        private readonly Dictionary<string, string> cabeceras = new Dictionary<string, string>();

        public static Respuesta Json(int estado, Dictionary<string, object> datos)
        {
            return new Respuesta { estado = estado, tipo = "application/json; charset=utf-8", cuerpo = Encoding.UTF8.GetBytes(new JavaScriptSerializer().Serialize(datos)) };
        }

        public static Respuesta Error(int estado, string codigo, string mensaje)
        {
            return Json(estado, new Dictionary<string, object> { { "codigo", codigo }, { "mensaje", mensaje } });
        }

        public static Respuesta Jpeg(byte[] imagen, string origen, bool simulacion)
        {
            return Imagen(imagen, "image/jpeg", origen, simulacion);
        }

        public static Respuesta Imagen(byte[] imagen, string mime, string origen, bool simulacion)
        {
            var r = new Respuesta { estado = 200, tipo = mime, cuerpo = imagen };
            if (origen != null) r.cabeceras["X-Captura-Origen"] = origen;
            r.cabeceras["X-Simulacion"] = simulacion ? "1" : "0";
            return r;
        }

        public static Respuesta Preflight(bool redPrivada)
        {
            var r = new Respuesta { estado = 204, cuerpo = new byte[0] };
            r.cabeceras["Access-Control-Allow-Methods"] = "GET, POST, DELETE, OPTIONS";
            r.cabeceras["Access-Control-Allow-Headers"] = "Authorization, X-Sesion";
            r.cabeceras["Access-Control-Max-Age"] = "60";
            // Chrome exige este permiso para que una página web llame a un servicio de la red local.
            if (redPrivada) r.cabeceras["Access-Control-Allow-Private-Network"] = "true";
            return r;
        }

        public void Cors(string origen)
        {
            cabeceras["Access-Control-Allow-Origin"] = origen;
            cabeceras["Access-Control-Expose-Headers"] = "X-Captura-Origen, X-Simulacion";
            cabeceras["Vary"] = "Origin";
        }

        public void Escribir(Stream flujo)
        {
            var sb = new StringBuilder();
            sb.Append("HTTP/1.1 ").Append(estado).Append(' ').Append(Razon(estado)).Append("\r\n");
            if (tipo != null) sb.Append("Content-Type: ").Append(tipo).Append("\r\n");
            sb.Append("Content-Length: ").Append(cuerpo.Length).Append("\r\n");
            sb.Append("Cache-Control: no-store\r\nX-Content-Type-Options: nosniff\r\nConnection: close\r\n");
            foreach (var c in cabeceras) sb.Append(c.Key).Append(": ").Append(c.Value).Append("\r\n");
            sb.Append("\r\n");
            byte[] cabecera = Encoding.ASCII.GetBytes(sb.ToString());
            try
            {
                flujo.Write(cabecera, 0, cabecera.Length);
                flujo.Write(cuerpo, 0, cuerpo.Length);
            }
            catch (IOException) { }
        }

        private static string Razon(int estado)
        {
            switch (estado)
            {
                case 200: return "OK";
                case 201: return "Created";
                case 204: return "No Content";
                case 401: return "Unauthorized";
                case 403: return "Forbidden";
                case 404: return "Not Found";
                case 409: return "Conflict";
                case 502: return "Bad Gateway";
                case 503: return "Service Unavailable";
                default: return "Error";
            }
        }
    }
}
