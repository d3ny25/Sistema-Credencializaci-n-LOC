using System;
using System.Collections.Generic;
using System.Linq;
using System.Management;
using System.Printing;

namespace AgenteEstacion
{
    public static class EstadoImpresora
    {
        public const string Lista = "lista";
        public const string ColaNoEncontrada = "cola_no_encontrada";
        public const string Desconectada = "impresora_desconectada";
        public const string FueraDeLinea = "fuera_de_linea";
        public const string Pausada = "pausada";
        public const string Error = "error";
        public const string RequiereAtencion = "requiere_atencion";
    }

    /// <summary>Estado de un trabajo según la cola de Windows. Ninguno prueba que la tarjeta física salió bien.</summary>
    public static class EstadoTrabajo
    {
        public const string NoEncontrado = "no_encontrado";
        public const string EnCola = "en_cola";
        public const string Imprimiendo = "imprimiendo";
        public const string CompletadoEnCola = "completado_en_cola";
        public const string SalioDeCola = "salio_de_cola";
        public const string Error = "error";
        public const string Eliminado = "eliminado";
        public const string EnOtraCola = "en_otra_cola";
    }

    public class EstadoCola
    {
        public string Estado;
        public string Cola;
        public string Controlador;
        public string Puerto;
        public int Trabajos;
        public bool DispositivoPresente;
        public string[] Alertas = new string[0];
    }

    public class InfoTrabajo
    {
        public string Estado;
        public int? JobId;
        public string Cola;
        public string[] Alertas = new string[0];
    }

    /// <summary>
    /// Monitor de impresión de SOLO LECTURA: nunca envía, cancela ni modifica trabajos o colas. El contenido lo imprime
    /// el navegador desde la vista de snapshot de Laravel; el agente solo observa la cola para informar su estado.
    /// </summary>
    public interface IMonitorImpresion
    {
        string Nombre { get; }
        bool EsSimulacion { get; }
        EstadoCola Estado();
        /// <summary>Busca el trabajo cuyo nombre de documento contiene el identificador dado (sin datos personales).</summary>
        InfoTrabajo BuscarTrabajo(string documento);
    }

    /// <summary>Cola real de Windows (System.Printing) y presencia física del dispositivo (WMI, solo dispositivos presentes).</summary>
    public class MonitorImpresionWindows : IMonitorImpresion
    {
        private readonly string cola;
        private readonly string patronPnp;

        public MonitorImpresionWindows(string cola, string patronPnp)
        {
            this.cola = cola;
            this.patronPnp = patronPnp;
        }

        public string Nombre { get { return "windows_spooler"; } }
        public bool EsSimulacion { get { return false; } }

        public EstadoCola Estado()
        {
            var resultado = new EstadoCola { Cola = cola, DispositivoPresente = DispositivoPresente() };
            using (var servidor = new LocalPrintServer())
            {
                PrintQueue q = Colas(servidor).FirstOrDefault(c => c.Name == cola);
                if (q == null)
                {
                    resultado.Estado = EstadoImpresora.ColaNoEncontrada;
                    return resultado;
                }
                using (q)
                {
                    q.Refresh();
                    resultado.Controlador = q.QueueDriver.Name;
                    resultado.Puerto = q.QueuePort.Name;
                    resultado.Trabajos = q.NumberOfJobs;

                    var alertas = new List<string>();
                    if (q.IsOffline) alertas.Add("fuera_de_linea");
                    if (q.IsNotAvailable) alertas.Add("no_disponible");
                    if (q.IsPaused) alertas.Add("pausada");
                    if (q.IsInError) alertas.Add("en_error");
                    if (q.IsPaperJammed) alertas.Add("atasco");
                    if (q.IsOutOfPaper) alertas.Add("sin_tarjetas");
                    if (q.HasPaperProblem) alertas.Add("problema_de_tarjetas");
                    if (q.IsDoorOpened) alertas.Add("puerta_abierta");
                    if (q.NeedUserIntervention) alertas.Add("requiere_intervencion");
                    if (q.IsTonerLow) alertas.Add("cinta_baja");
                    resultado.Alertas = alertas.ToArray();

                    if (!resultado.DispositivoPresente) resultado.Estado = EstadoImpresora.Desconectada;
                    else if (q.IsInError || q.IsPaperJammed || q.IsOutOfPaper || q.HasPaperProblem) resultado.Estado = EstadoImpresora.Error;
                    else if (q.NeedUserIntervention || q.IsDoorOpened) resultado.Estado = EstadoImpresora.RequiereAtencion;
                    else if (q.IsOffline || q.IsNotAvailable) resultado.Estado = EstadoImpresora.FueraDeLinea;
                    else if (q.IsPaused) resultado.Estado = EstadoImpresora.Pausada;
                    else resultado.Estado = EstadoImpresora.Lista;
                }
            }
            return resultado;
        }

        public InfoTrabajo BuscarTrabajo(string documento)
        {
            using (var servidor = new LocalPrintServer())
            {
                foreach (PrintQueue q in Colas(servidor))
                {
                    using (q)
                    {
                        q.Refresh();
                        foreach (PrintSystemJobInfo trabajo in q.GetPrintJobInfoCollection())
                        {
                            using (trabajo)
                            {
                                // Solo se compara el nombre: nunca se devuelve el nombre de otros documentos.
                                if (trabajo.Name == null || trabajo.Name.IndexOf(documento, StringComparison.Ordinal) < 0) continue;
                                if (q.Name != cola) return new InfoTrabajo { Estado = EstadoTrabajo.EnOtraCola, Cola = q.Name, JobId = trabajo.JobIdentifier };
                                return Clasificar(trabajo.JobStatus, trabajo.JobIdentifier, q.Name);
                            }
                        }
                    }
                }
            }
            return new InfoTrabajo { Estado = EstadoTrabajo.NoEncontrado, Cola = cola };
        }

        public static InfoTrabajo Clasificar(PrintJobStatus s, int jobId, string nombreCola)
        {
            var alertas = new List<string>();
            if ((s & PrintJobStatus.Error) != 0) alertas.Add("error");
            if ((s & PrintJobStatus.Offline) != 0) alertas.Add("fuera_de_linea");
            if ((s & PrintJobStatus.PaperOut) != 0) alertas.Add("sin_tarjetas");
            if ((s & PrintJobStatus.UserIntervention) != 0) alertas.Add("requiere_intervencion");
            if ((s & PrintJobStatus.Blocked) != 0) alertas.Add("bloqueado");
            if ((s & PrintJobStatus.Paused) != 0) alertas.Add("pausado");

            string estado;
            if (alertas.Any(a => a != "pausado")) estado = EstadoTrabajo.Error;
            else if ((s & (PrintJobStatus.Deleted | PrintJobStatus.Deleting)) != 0) estado = EstadoTrabajo.Eliminado;
            else if ((s & (PrintJobStatus.Printed | PrintJobStatus.Completed)) != 0) estado = EstadoTrabajo.CompletadoEnCola;
            else if ((s & PrintJobStatus.Printing) != 0) estado = EstadoTrabajo.Imprimiendo;
            else estado = EstadoTrabajo.EnCola;
            return new InfoTrabajo { Estado = estado, JobId = jobId, Cola = nombreCola, Alertas = alertas.ToArray() };
        }

        private static IEnumerable<PrintQueue> Colas(LocalPrintServer servidor)
        {
            return servidor.GetPrintQueues(new[] { EnumeratedPrintQueueTypes.Local, EnumeratedPrintQueueTypes.Connections }).Cast<PrintQueue>();
        }

        // Win32_PnPEntity solo enumera dispositivos presentes (comprobado: 0 resultados con la Zebra desconectada).
        private bool DispositivoPresente()
        {
            using (var buscador = new ManagementObjectSearcher("SELECT PNPDeviceID FROM Win32_PnPEntity WHERE PNPClass = 'Printer'"))
            {
                foreach (ManagementObject d in buscador.Get())
                {
                    using (d)
                    {
                        string id = d["PNPDeviceID"] as string;
                        if (id != null && id.IndexOf(patronPnp, StringComparison.OrdinalIgnoreCase) >= 0) return true;
                    }
                }
            }
            return false;
        }
    }

    /// <summary>
    /// SIMULACIÓN de la cola (--simulado-impresora[=lista|desconectada|error|otra-cola|sin-trabajo]). El trabajo simulado
    /// avanza en cada consulta: en_cola → imprimiendo → completado_en_cola → deja la cola. Nunca toca el spooler real.
    /// </summary>
    public class MonitorImpresionSimulado : IMonitorImpresion
    {
        public const string ColaSimulada = "Zebra ZXP Series 7 USB Card Printer (SIMULACIÓN)";
        private readonly string modo;
        private int consultas;

        public MonitorImpresionSimulado(string modo)
        {
            string[] validos = { "lista", "desconectada", "error", "otra-cola", "sin-trabajo" };
            this.modo = validos.Contains(modo) ? modo : "lista";
        }

        public string Nombre { get { return "simulado"; } }
        public bool EsSimulacion { get { return true; } }

        public EstadoCola Estado()
        {
            bool presente = modo != "desconectada";
            return new EstadoCola
            {
                Cola = ColaSimulada,
                Controlador = "SIMULACIÓN",
                Puerto = "SIMULACIÓN",
                DispositivoPresente = presente,
                Estado = !presente ? EstadoImpresora.Desconectada : (modo == "error" ? EstadoImpresora.Error : EstadoImpresora.Lista),
                Alertas = modo == "error" ? new[] { "sin_tarjetas" } : (presente ? new string[0] : new[] { "fuera_de_linea" }),
            };
        }

        public InfoTrabajo BuscarTrabajo(string documento)
        {
            consultas++;
            if (modo == "sin-trabajo" || modo == "desconectada") return new InfoTrabajo { Estado = EstadoTrabajo.NoEncontrado, Cola = ColaSimulada };
            if (modo == "otra-cola") return new InfoTrabajo { Estado = EstadoTrabajo.EnOtraCola, Cola = "Microsoft Print to PDF (SIMULACIÓN)", JobId = 7 };
            if (modo == "error" && consultas >= 2) return new InfoTrabajo { Estado = EstadoTrabajo.Error, Cola = ColaSimulada, JobId = 7, Alertas = new[] { "sin_tarjetas" } };
            if (consultas == 1) return new InfoTrabajo { Estado = EstadoTrabajo.EnCola, Cola = ColaSimulada, JobId = 7 };
            if (consultas == 2) return new InfoTrabajo { Estado = EstadoTrabajo.Imprimiendo, Cola = ColaSimulada, JobId = 7 };
            if (consultas == 3) return new InfoTrabajo { Estado = EstadoTrabajo.CompletadoEnCola, Cola = ColaSimulada, JobId = 7 };
            return new InfoTrabajo { Estado = EstadoTrabajo.NoEncontrado, Cola = ColaSimulada };
        }
    }
}
