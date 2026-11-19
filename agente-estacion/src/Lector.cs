using System;
using System.Collections.Concurrent;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;

namespace AgenteEstacion
{
    public static class EstadoLector
    {
        public const string SinVerificar = "sin_verificar";
        public const string SdkNoInstalado = "sdk_no_instalado";
        public const string SdkIncompatible = "sdk_incompatible";
        public const string Desconectado = "desconectado";
        public const string Conectado = "conectado";
        public const string EnUso = "en_uso";
        public const string ErrorInicializacion = "error_inicializacion";
    }

    /// <summary>
    /// Contrato del lector de huellas. Las imágenes se devuelven como PNG en escala de grises, en memoria.
    /// Estado() no sondea el hardware; Verificar() sí (inicializa y libera el lector).
    /// </summary>
    public interface ILectorHuella
    {
        string Nombre { get; }
        bool EsSimulacion { get; }
        string Estado();
        string Modelo();
        string Detalle();
        string Verificar();
        void Abrir();
        void IniciarVistaPrevia();
        byte[] Fotograma();
        byte[] Capturar();
        /// <summary>Libera el lector y los recursos nativos. Debe poder llamarse siempre.</summary>
        void Cerrar();
    }

    /// <summary>Sin SDK utilizable: informa el motivo y no finge ningún lector.</summary>
    public class LectorNoDisponible : ILectorHuella
    {
        private readonly string estado;
        private readonly string detalle;

        public LectorNoDisponible(string estado, string detalle)
        {
            this.estado = estado;
            this.detalle = detalle;
        }

        public string Nombre { get { return "ninguno"; } }
        public bool EsSimulacion { get { return false; } }
        public string Estado() { return estado; }
        public string Modelo() { return null; }
        public string Detalle() { return detalle; }
        public string Verificar() { return estado; }
        public void Abrir() { throw new CamaraException(estado, 503, detalle); }
        public void IniciarVistaPrevia() { throw new CamaraException(estado, 503, detalle); }
        public byte[] Fotograma() { throw new CamaraException(estado, 503, detalle); }
        public byte[] Capturar() { throw new CamaraException(estado, 503, detalle); }
        public void Cerrar() { }
    }

    /// <summary>
    /// SIMULACIÓN para pruebas sin lector físico (--simulado-lector[=conectado|desconectado|falla-captura|en-uso]).
    /// Genera un DIB de 8 bits como el del SDK y lo convierte con el mismo código que la captura real; el PNG lleva
    /// un fragmento tEXt "Simulacion" que Laravel rechaza, así que nunca puede guardarse como evidencia.
    /// </summary>
    public class LectorSimulado : ILectorHuella
    {
        private readonly string modo;
        private bool abierto;
        private bool vistaPrevia;
        private int numero;

        public LectorSimulado(string modo)
        {
            string[] validos = { "conectado", "desconectado", "falla-captura", "en-uso" };
            this.modo = validos.Contains(modo) ? modo : "conectado";
        }

        public string Nombre { get { return "simulado"; } }
        public bool EsSimulacion { get { return true; } }

        public string Estado()
        {
            if (modo == "desconectado") return EstadoLector.Desconectado;
            if (modo == "en-uso") return EstadoLector.EnUso;
            return EstadoLector.Conectado;
        }

        public string Modelo() { return Estado() == EstadoLector.Conectado ? "SIMULACIÓN (sin lector físico)" : null; }
        public string Detalle() { return "Modo simulación: ninguna imagen proviene de un lector."; }
        public string Verificar() { return Estado(); }

        public void Abrir()
        {
            if (modo == "desconectado") throw new CamaraException(EstadoLector.Desconectado, 503, "SIMULACIÓN: el lector está desconectado.");
            if (modo == "en-uso") throw new CamaraException(EstadoLector.EnUso, 409, "SIMULACIÓN: otra aplicación está usando el lector.");
            abierto = true;
        }

        public void IniciarVistaPrevia()
        {
            ExigirAbierto();
            vistaPrevia = true;
        }

        public byte[] Fotograma()
        {
            ExigirAbierto();
            if (!vistaPrevia) throw new CamaraException("vista_previa_inactiva", 409, "La vista previa no está activa.");
            return ImagenDib.APng(DibSintetico(), true);
        }

        public byte[] Capturar()
        {
            ExigirAbierto();
            if (modo == "falla-captura") throw new CamaraException("error_sdk", 502, "SIMULACIÓN: USB_GetStillImage devolvió un error.");
            vistaPrevia = false;
            return ImagenDib.APng(DibSintetico(), true);
        }

        public void Cerrar()
        {
            abierto = false;
            vistaPrevia = false;
        }

        private void ExigirAbierto()
        {
            if (!abierto) throw new CamaraException("lector_no_abierto", 409, "El lector no está inicializado para esta sesión.");
        }

        // 8 bits, ancho múltiplo de 4, filas de abajo hacia arriba: el formato que documenta USB4XX.H.
        private byte[] DibSintetico()
        {
            numero++;
            const int ancho = 400, alto = 500;
            byte[] dib = new byte[40 + 1024 + ancho * alto];
            BitConverter.GetBytes(40).CopyTo(dib, 0);
            BitConverter.GetBytes(ancho).CopyTo(dib, 4);
            BitConverter.GetBytes(alto).CopyTo(dib, 8);
            BitConverter.GetBytes((short)1).CopyTo(dib, 12);
            BitConverter.GetBytes((short)8).CopyTo(dib, 14);
            for (int i = 0; i < 256; i++) { dib[40 + i * 4] = (byte)i; dib[41 + i * 4] = (byte)i; dib[42 + i * 4] = (byte)i; }
            for (int y = 0; y < alto; y++)
            {
                for (int x = 0; x < ancho; x++)
                {
                    double dx = x - 200, dy = (y - 250) * 0.8;
                    double d = Math.Sqrt(dx * dx + dy * dy);
                    dib[40 + 1024 + y * ancho + x] = d > 190 ? (byte)250 : (byte)(128 + 110 * Math.Sin(d / 3.0 + numero));
                }
            }
            return dib;
        }
    }

    /// <summary>
    /// Adaptador real sobre Cross Match USB SDK 5.4.0.1 (USB4XX.dll, x86). Solo usa funciones declaradas en USB4XX.H
    /// y en el ejemplo oficial CSharpSample (VerifierNative.cs). Toda llamada al SDK se ejecuta en un único hilo.
    /// La secuencia replica la del ejemplo: RegisterCallbacks → SetDetectFirstFinger → Initialize(0,0,NULL)
    /// (un reintento si hay dedo presente) → SetLiveMode/GetLiveImage → SetLiveMode(false)/GetStillImage → Dispose.
    /// PENDIENTE DE VALIDACIÓN FÍSICA: comportamiento sin bucle de mensajes de Windows y ciclos repetidos Initialize/Dispose.
    /// </summary>
    public class LectorCrossMatchSdk : ILectorHuella
    {
        public static readonly string[] FuncionesRequeridas =
        {
            "USB_RegisterCallbacks", "USB_SetDetectFirstFinger", "USB_Initialize", "USB_GetProductCode",
            "USB_SetLiveMode", "USB_GetLiveImage", "USB_GetStillImage", "USB_Dispose",
        };

        private const byte ProductoVerifier300Lc2 = 0x30; // USB_VERIFIER_300LC2 en USB4XX.H
        private const int EsperaMs = 15000;

        private readonly HiloSdk hilo = new HiloSdk("Cross Match SDK");
        private readonly string carpeta;
        private string estado = EstadoLector.SinVerificar;
        private string detalle = "Use \"Verificar lector\" para comprobar la conexión.";
        private byte producto;
        private bool abierto;
        private volatile bool desconectado;

        // Referencias vivas: el SDK guarda los punteros de las devoluciones de llamada.
        private static Usb4xx.Devolucion alConectar;
        private static Usb4xx.Devolucion alDesconectar;

        private LectorCrossMatchSdk(string carpeta)
        {
            this.carpeta = carpeta;
        }

        /// <summary>Carga y comprueba la DLL. Si no puede usarse, devuelve un LectorNoDisponible con el motivo exacto.</summary>
        public static ILectorHuella Crear(string carpeta)
        {
            if (string.IsNullOrEmpty(carpeta))
            {
                return new LectorNoDisponible(EstadoLector.SdkNoInstalado, "No se configuró rutaSdkCrossMatch en agente.config.json.");
            }
            string dll = Path.Combine(carpeta, "USB4XX.dll");
            if (!File.Exists(dll))
            {
                return new LectorNoDisponible(EstadoLector.SdkNoInstalado, "No se encontró USB4XX.dll en la carpeta configurada.");
            }

            // Sus dependencias (intl.dll, libglib-2.0-0.dll) se resuelven desde la misma carpeta.
            Usb4xx.SetDllDirectory(carpeta);
            IntPtr modulo = Usb4xx.LoadLibrary(dll);
            if (modulo == IntPtr.Zero)
            {
                return new LectorNoDisponible(EstadoLector.SdkIncompatible, "No se pudo cargar USB4XX.dll (error de Windows " + Marshal.GetLastWin32Error() + "; ¿arquitectura o dependencias?).");
            }
            string[] faltantes = FuncionesRequeridas.Where(f => Usb4xx.GetProcAddress(modulo, f) == IntPtr.Zero).ToArray();
            if (faltantes.Length > 0)
            {
                return new LectorNoDisponible(EstadoLector.SdkIncompatible, "USB4XX.dll no exporta: " + string.Join(", ", faltantes) + ".");
            }
            return new LectorCrossMatchSdk(carpeta);
        }

        public string Nombre { get { return "crossmatch_usb_sdk"; } }
        public bool EsSimulacion { get { return false; } }

        public string Estado()
        {
            return abierto && desconectado ? EstadoLector.Desconectado : estado;
        }

        public string Modelo()
        {
            if (producto == 0) return null;
            return producto == ProductoVerifier300Lc2 ? "Cross Match Verifier 300 LC 2.0" : "Cross Match (código de producto 0x" + producto.ToString("X2") + ")";
        }

        public string Detalle() { return detalle; }

        public string Verificar()
        {
            if (abierto) return Estado();
            try
            {
                hilo.Ejecutar(() => { Inicializar(); Liberar(); return true; }, EsperaMs);
                Fijar(EstadoLector.Conectado, "Lector detectado e inicializado; se liberó tras la verificación.");
            }
            catch (CamaraException e)
            {
                Fijar(EstadoDe(e.Codigo), e.Message);
            }
            return estado;
        }

        public void Abrir()
        {
            try
            {
                hilo.Ejecutar(() => { Inicializar(); return true; }, EsperaMs);
                Fijar(EstadoLector.Conectado, "Lector inicializado para la captura.");
            }
            catch (CamaraException e)
            {
                Fijar(EstadoDe(e.Codigo), e.Message);
                throw;
            }
        }

        public void IniciarVistaPrevia()
        {
            hilo.Ejecutar(() => { ExigirAbierto(); Comprobar(Usb4xx.USB_SetLiveMode(true), "USB_SetLiveMode"); return true; }, EsperaMs);
        }

        public byte[] Fotograma()
        {
            return hilo.Ejecutar(() =>
            {
                ExigirAbierto();
                IntPtr imagen = IntPtr.Zero;
                Comprobar(Usb4xx.USB_GetLiveImage(ref imagen), "USB_GetLiveImage");
                return ImagenDib.APng(ImagenDib.Copiar(imagen), false);
            }, EsperaMs);
        }

        public byte[] Capturar()
        {
            return hilo.Ejecutar(() =>
            {
                ExigirAbierto();
                // USB4XX.H: la imagen fija a resolución completa se obtiene fuera del modo en vivo.
                Comprobar(Usb4xx.USB_SetLiveMode(false), "USB_SetLiveMode");
                IntPtr imagen = IntPtr.Zero;
                Comprobar(Usb4xx.USB_GetStillImage(ref imagen), "USB_GetStillImage");
                return ImagenDib.APng(ImagenDib.Copiar(imagen), false);
            }, EsperaMs);
        }

        public void Cerrar()
        {
            if (!abierto) return;
            try { hilo.Ejecutar(() => { Liberar(); return true; }, EsperaMs); } catch (CamaraException) { }
        }

        // ---- Solo en el hilo del SDK ----

        private void Inicializar()
        {
            if (abierto) return;
            if (alConectar == null)
            {
                alConectar = nulo => { desconectado = false; };
                alDesconectar = nulo => { desconectado = true; };
            }
            // USB4XX.H: RegisterCallbacks debe ser la primera llamada, antes de USB_Initialize.
            // Comprobado sin lector conectado: ya esta llamada devuelve USB_NOT_READY (unos 3 s).
            int st = Usb4xx.USB_RegisterCallbacks(alConectar, alDesconectar);
            if (st != Usb4xx.Ok)
            {
                Usb4xx.USB_Dispose();
                throw Error(st, "USB_RegisterCallbacks");
            }
            Usb4xx.USB_SetDetectFirstFinger(true);
            st = Usb4xx.USB_Initialize(0, 0, IntPtr.Zero);
            if (st == Usb4xx.InitFailFingerPresent) st = Usb4xx.USB_Initialize(0, 0, IntPtr.Zero);
            if (st != Usb4xx.Ok)
            {
                Usb4xx.USB_Dispose();
                throw Error(st, "USB_Initialize");
            }
            byte codigo = 0;
            if (Usb4xx.USB_GetProductCode(ref codigo) == Usb4xx.Ok) producto = codigo;
            abierto = true;
            desconectado = false;
        }

        private void Liberar()
        {
            if (!abierto) return;
            Usb4xx.USB_SetLiveMode(false);
            // USB4XX.H: USB_Dispose libera memoria, hilos y devoluciones de llamada y permite reinicializar bajo demanda.
            Usb4xx.USB_Dispose();
            abierto = false;
        }

        private void ExigirAbierto()
        {
            if (!abierto) throw new CamaraException("lector_no_abierto", 409, "El lector no está inicializado para esta sesión.");
            if (desconectado) throw new CamaraException(EstadoLector.Desconectado, 503, "El lector se desconectó.");
        }

        private static void Comprobar(int st, string funcion)
        {
            if (st != Usb4xx.Ok) throw Error(st, funcion);
        }

        private static CamaraException Error(int st, string funcion)
        {
            switch (st)
            {
                case 2: return new CamaraException(EstadoLector.Desconectado, 503, "El lector no responde (" + funcion + ": USB_NOT_READY). ¿Está conectado?");
                case 29: return new CamaraException(EstadoLector.ErrorInicializacion, 502, "El lector V300 LC no se inicializó correctamente (USB_V300LC_INIT_FAILED).");
                case 30: return new CamaraException("dedo_presente", 409, "Retire el dedo y limpie el cristal del lector; después reintente.");
                case 32: return new CamaraException(EstadoLector.ErrorInicializacion, 502, "El lector requiere un puerto USB 2.0 de alta velocidad.");
                case 37: return new CamaraException(EstadoLector.EnUso, 409, "Otra aplicación está usando el lector (por ejemplo, Fingerprint Capture). Ciérrela y reintente.");
                default: return new CamaraException("error_sdk", 502, funcion + " devolvió el estado " + st + " del SDK.");
            }
        }

        private static string EstadoDe(string codigo)
        {
            if (codigo == EstadoLector.Desconectado || codigo == EstadoLector.EnUso || codigo == EstadoLector.SdkIncompatible) return codigo;
            return EstadoLector.ErrorInicializacion;
        }

        private void Fijar(string nuevo, string texto)
        {
            estado = nuevo;
            detalle = texto;
        }
    }

    /// <summary>Declaraciones P/Invoke tomadas de USB4XX.H y del ejemplo oficial VerifierNative.cs (WINAPI = stdcall).</summary>
    internal static class Usb4xx
    {
        public const int Ok = 0;
        public const int InitFailFingerPresent = 30;

        [UnmanagedFunctionPointer(CallingConvention.StdCall)]
        public delegate void Devolucion(IntPtr nulo);

        [DllImport("kernel32", SetLastError = true, CharSet = CharSet.Unicode)]
        public static extern bool SetDllDirectory(string ruta);

        [DllImport("kernel32", SetLastError = true, CharSet = CharSet.Unicode)]
        public static extern IntPtr LoadLibrary(string ruta);

        [DllImport("kernel32", CharSet = CharSet.Ansi, ExactSpelling = true)]
        public static extern IntPtr GetProcAddress(IntPtr modulo, string nombre);

        [DllImport("USB4XX.dll")] public static extern int USB_RegisterCallbacks(Devolucion conectar, Devolucion desconectar);
        [DllImport("USB4XX.dll")] public static extern int USB_SetDetectFirstFinger([MarshalAs(UnmanagedType.Bool)] bool habilitar);
        [DllImport("USB4XX.dll")] public static extern int USB_Initialize(short ancho, short alto, IntPtr hWnd);
        [DllImport("USB4XX.dll")] public static extern int USB_GetProductCode(ref byte codigo);
        [DllImport("USB4XX.dll")] public static extern int USB_SetLiveMode([MarshalAs(UnmanagedType.Bool)] bool enVivo);
        [DllImport("USB4XX.dll")] public static extern int USB_GetLiveImage(ref IntPtr imagen);
        [DllImport("USB4XX.dll")] public static extern int USB_GetStillImage(ref IntPtr imagen);
        [DllImport("USB4XX.dll")] public static extern int USB_Dispose();
    }

    /// <summary>Ejecuta todo el trabajo del SDK nativo en un solo hilo, en orden, con tiempo de espera.</summary>
    public class HiloSdk
    {
        private readonly BlockingCollection<Action> cola = new BlockingCollection<Action>();

        public HiloSdk(string nombre)
        {
            var hilo = new Thread(() => { foreach (Action trabajo in cola.GetConsumingEnumerable()) trabajo(); });
            hilo.IsBackground = true;
            hilo.Name = nombre;
            hilo.SetApartmentState(ApartmentState.STA);
            hilo.Start();
        }

        public T Ejecutar<T>(Func<T> trabajo, int esperaMs)
        {
            T resultado = default(T);
            Exception error = null;
            var listo = new ManualResetEventSlim(false);
            cola.Add(() =>
            {
                try { resultado = trabajo(); }
                catch (Exception e) { error = e; }
                finally { listo.Set(); }
            });
            if (!listo.Wait(esperaMs)) throw new CamaraException("sdk_sin_respuesta", 504, "El SDK del lector no respondió a tiempo.");
            if (error is CamaraException) throw (CamaraException)error;
            if (error is DllNotFoundException || error is EntryPointNotFoundException || error is BadImageFormatException)
            {
                throw new CamaraException(EstadoLector.SdkIncompatible, 503, "El SDK del lector no pudo usarse: " + error.GetType().Name + ".");
            }
            if (error != null) throw new CamaraException("error_sdk", 502, "Error del SDK del lector: " + error.GetType().Name + ".");
            return resultado;
        }
    }

    /// <summary>DIB de 8 bits del SDK (BITMAPINFOHEADER + paleta + píxeles) → PNG en escala de grises, en memoria.</summary>
    public static class ImagenDib
    {
        private const int MaxAncho = 1280, MaxAlto = 1024; // MAX_WIDTH / MAX_HEIGHT de USB4XX.H

        /// <summary>Copia el DIB apuntado por USB_IMAGE.lpBMI. La memoria pertenece al SDK y no se libera aquí.</summary>
        public static byte[] Copiar(IntPtr dib)
        {
            if (dib == IntPtr.Zero) throw new CamaraException("imagen_invalida", 502, "El SDK no devolvió ninguna imagen.");
            byte[] cabecera = new byte[40];
            Marshal.Copy(dib, cabecera, 0, 40);
            Formato f = Leer(cabecera);
            byte[] copia = new byte[f.Datos + f.Paso * f.Alto];
            Marshal.Copy(dib, copia, 0, copia.Length);
            return copia;
        }

        public static byte[] APng(byte[] dib, bool simulacion)
        {
            Formato f = Leer(dib);
            if (dib.Length < f.Datos + f.Paso * f.Alto) throw new CamaraException("imagen_invalida", 502, "La imagen del lector está incompleta.");

            using (var bmp = new Bitmap(f.Ancho, f.Alto, PixelFormat.Format8bppIndexed))
            {
                ColorPalette paleta = bmp.Palette;
                for (int i = 0; i < 256; i++)
                {
                    int p = 40 + i * 4;
                    paleta.Entries[i] = i < f.Colores ? Color.FromArgb(dib[p + 2], dib[p + 1], dib[p]) : Color.FromArgb(i, i, i);
                }
                bmp.Palette = paleta;

                BitmapData datos = bmp.LockBits(new Rectangle(0, 0, f.Ancho, f.Alto), ImageLockMode.WriteOnly, PixelFormat.Format8bppIndexed);
                try
                {
                    for (int y = 0; y < f.Alto; y++)
                    {
                        // Alto positivo en el DIB: las filas vienen de abajo hacia arriba.
                        int origen = f.Datos + (f.DeAbajoArriba ? f.Alto - 1 - y : y) * f.Paso;
                        Marshal.Copy(dib, origen, new IntPtr(datos.Scan0.ToInt64() + (long)y * datos.Stride), f.Ancho);
                    }
                }
                finally
                {
                    bmp.UnlockBits(datos);
                }

                using (var ms = new MemoryStream())
                {
                    bmp.Save(ms, ImageFormat.Png);
                    byte[] png = ms.ToArray();
                    return simulacion ? InsertarTexto(png, "Simulacion", "agente-estacion: imagen simulada, no es una huella real") : png;
                }
            }
        }

        private struct Formato
        {
            public int Ancho, Alto, Paso, Datos, Colores;
            public bool DeAbajoArriba;
        }

        private static Formato Leer(byte[] cabecera)
        {
            if (cabecera.Length < 40) throw new CamaraException("imagen_invalida", 502, "La imagen del lector no tiene cabecera.");
            int tam = BitConverter.ToInt32(cabecera, 0);
            int ancho = BitConverter.ToInt32(cabecera, 4);
            int alto = BitConverter.ToInt32(cabecera, 8);
            int bits = BitConverter.ToInt16(cabecera, 14);
            int compresion = BitConverter.ToInt32(cabecera, 16);
            int usados = BitConverter.ToInt32(cabecera, 32);
            if (tam != 40 || bits != 8 || compresion != 0 || ancho <= 0 || ancho > MaxAncho || alto == 0 || Math.Abs(alto) > MaxAlto || usados < 0 || usados > 256)
            {
                throw new CamaraException("imagen_invalida", 502, "El lector devolvió una imagen con un formato no esperado.");
            }
            int colores = usados == 0 ? 256 : usados;
            return new Formato
            {
                Ancho = ancho,
                Alto = Math.Abs(alto),
                DeAbajoArriba = alto > 0,
                Paso = ((ancho * 8 + 31) / 32) * 4,
                Datos = 40 + colores * 4,
                Colores = colores,
            };
        }

        /// <summary>Inserta un fragmento tEXt justo después de IHDR (la firma PNG ocupa 8 bytes y IHDR 25).</summary>
        private static byte[] InsertarTexto(byte[] png, string clave, string texto)
        {
            byte[] tipoYDatos = Encoding.ASCII.GetBytes("tEXt" + clave + "\0" + texto);
            int largoDatos = tipoYDatos.Length - 4;
            byte[] fragmento = new byte[12 + largoDatos];
            fragmento[0] = (byte)(largoDatos >> 24); fragmento[1] = (byte)(largoDatos >> 16); fragmento[2] = (byte)(largoDatos >> 8); fragmento[3] = (byte)largoDatos;
            Array.Copy(tipoYDatos, 0, fragmento, 4, tipoYDatos.Length);
            uint crc = Crc32(tipoYDatos);
            fragmento[fragmento.Length - 4] = (byte)(crc >> 24); fragmento[fragmento.Length - 3] = (byte)(crc >> 16);
            fragmento[fragmento.Length - 2] = (byte)(crc >> 8); fragmento[fragmento.Length - 1] = (byte)crc;

            const int finIhdr = 33;
            byte[] salida = new byte[png.Length + fragmento.Length];
            Array.Copy(png, 0, salida, 0, finIhdr);
            Array.Copy(fragmento, 0, salida, finIhdr, fragmento.Length);
            Array.Copy(png, finIhdr, salida, finIhdr + fragmento.Length, png.Length - finIhdr);
            return salida;
        }

        private static uint Crc32(byte[] datos)
        {
            uint crc = 0xFFFFFFFF;
            foreach (byte b in datos)
            {
                crc ^= b;
                for (int k = 0; k < 8; k++) crc = (crc & 1) != 0 ? (crc >> 1) ^ 0xEDB88320u : crc >> 1;
            }
            return ~crc;
        }
    }
}
