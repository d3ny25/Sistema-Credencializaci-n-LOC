using System;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Threading;

namespace AgenteEstacion
{
    /// <summary>
    /// Cámara Canon EOS (Rebel T6) por EDSDK, solo con Live View: abre sesión, dirige el Live View a la PC y descarga
    /// fotogramas JPEG en memoria. No usa el obturador ni la tarjeta SD (sin SD el disparo falla con 0x8D07) y al detener
    /// restaura Evf_Mode y Evf_OutputDevice. Secuencia validada en la estación (Etapa B4, 13-11-2026: 1056 × 704).
    ///
    /// EDSDK 3.8.20.0 x86 SIN firma digital, procedente de un paquete de terceros autorizado por el usuario para pruebas:
    /// se acepta solo si su SHA-256 coincide con el auditado. Para producción debe sustituirse por el EDSDK oficial.
    /// </summary>
    public class CamaraEdsdk : ICamara
    {
        public const string Sha256EdsdkAuditado = "5E34EB92C7924D343FF120438B6FDE377DD15495B95A3BE96B72A7FB2723834D";

        private const uint PropProductName = 0x00000002;
        private const uint PropEvfOutputDevice = 0x00000500;
        private const uint PropEvfMode = 0x00000501;
        private const uint EvfSalidaPc = 2;
        private const uint ErrNoListo = 0x0000A102; // EDS_ERR_OBJECT_NOTREADY: el Live View aún no entrega imagen
        private const int EsperaMs = 15000;

        private readonly HiloSdk hilo = new HiloSdk("Canon EDSDK");
        private IntPtr lista = IntPtr.Zero;
        private IntPtr camara = IntPtr.Zero;
        private bool sdkIniciado;
        private bool enVivo;
        private uint? modoOriginal;
        private uint? salidaOriginal;
        private string modelo;

        private CamaraEdsdk() { }

        /// <summary>Comprueba la DLL (existencia, hash auditado, carga y exportaciones). Si no puede usarse, devuelve CamaraSinSdk.</summary>
        public static ICamara Crear(string carpeta, out string motivo)
        {
            motivo = null;
            if (string.IsNullOrEmpty(carpeta)) { motivo = "No se configuró rutaEdsdk en agente.config.json."; return new CamaraSinSdk(); }
            string dll = Path.Combine(carpeta, "EDSDK.dll");
            if (!File.Exists(dll)) { motivo = "No se encontró EDSDK.dll en la carpeta configurada."; return new CamaraSinSdk(); }

            string hash;
            using (var sha = SHA256.Create()) using (var f = File.OpenRead(dll)) hash = BitConverter.ToString(sha.ComputeHash(f)).Replace("-", "");
            if (hash != Sha256EdsdkAuditado) { motivo = "EDSDK.dll no coincide con la versión auditada (hash distinto)."; return new CamaraSinSdk(); }

            Edsdk.SetDllDirectory(carpeta);
            IntPtr modulo = Edsdk.LoadLibrary(dll);
            if (modulo == IntPtr.Zero) { motivo = "No se pudo cargar EDSDK.dll (error de Windows " + Marshal.GetLastWin32Error() + ")."; return new CamaraSinSdk(); }
            string[] faltantes = Edsdk.Funciones.Where(f => Edsdk.GetProcAddress(modulo, f) == IntPtr.Zero).ToArray();
            if (faltantes.Length > 0) { motivo = "EDSDK.dll no exporta: " + string.Join(", ", faltantes) + "."; return new CamaraSinSdk(); }
            return new CamaraEdsdk();
        }

        public string Nombre { get { return "canon_edsdk"; } }
        public bool EsSimulacion { get { return false; } }

        /// <summary>Detecta la cámara sin abrir sesión (lista de cámaras del SDK). Con vista previa activa, responde de memoria.</summary>
        public string Estado()
        {
            if (enVivo) return EstadoCamara.Conectada;
            try
            {
                return hilo.Ejecutar(() =>
                {
                    IniciarSdk();
                    try
                    {
                        IntPtr l = IntPtr.Zero, c = IntPtr.Zero;
                        Comprobar(Edsdk.EdsGetCameraList(out l), "EdsGetCameraList");
                        try
                        {
                            int n;
                            Comprobar(Edsdk.EdsGetChildCount(l, out n), "EdsGetChildCount");
                            if (n < 1) return EstadoCamara.Desconectada;
                            Comprobar(Edsdk.EdsGetChildAtIndex(l, 0, out c), "EdsGetChildAtIndex");
                            Edsdk.DeviceInfo info;
                            if (Edsdk.EdsGetDeviceInfo(c, out info) == 0) modelo = info.Descripcion;
                            return EstadoCamara.Conectada;
                        }
                        finally
                        {
                            if (c != IntPtr.Zero) Edsdk.EdsRelease(c);
                            if (l != IntPtr.Zero) Edsdk.EdsRelease(l);
                        }
                    }
                    finally { TerminarSdk(); }
                }, EsperaMs);
            }
            catch (CamaraException) { return EstadoCamara.Desconectada; }
        }

        public string Modelo() { return modelo; }

        public void IniciarVistaPrevia()
        {
            hilo.Ejecutar(() =>
            {
                if (enVivo) return true;
                IniciarSdk();
                try
                {
                    Comprobar(Edsdk.EdsGetCameraList(out lista), "EdsGetCameraList");
                    int n;
                    Comprobar(Edsdk.EdsGetChildCount(lista, out n), "EdsGetChildCount");
                    if (n < 1) throw new CamaraException(EstadoCamara.Desconectada, 503, "No se detecta ninguna cámara Canon conectada y encendida.");
                    Comprobar(Edsdk.EdsGetChildAtIndex(lista, 0, out camara), "EdsGetChildAtIndex");
                    Comprobar(Edsdk.EdsOpenSession(camara), "EdsOpenSession");

                    uint valor;
                    if (Edsdk.EdsGetPropertyData(camara, PropEvfMode, 0, 4, out valor) == 0) modoOriginal = valor;
                    Comprobar(Edsdk.EdsGetPropertyData(camara, PropEvfOutputDevice, 0, 4, out valor), "Evf_OutputDevice");
                    salidaOriginal = valor;
                    if (modoOriginal.HasValue && modoOriginal.Value != 1) { uint uno = 1; Comprobar(Edsdk.EdsSetPropertyData(camara, PropEvfMode, 0, 4, ref uno), "Evf_Mode"); }
                    uint pc = EvfSalidaPc;
                    Comprobar(Edsdk.EdsSetPropertyData(camara, PropEvfOutputDevice, 0, 4, ref pc), "Evf_OutputDevice");
                    modelo = LeerModelo() ?? modelo;
                    enVivo = true;
                    return true;
                }
                catch
                {
                    Detener();
                    throw;
                }
            }, EsperaMs);
        }

        public byte[] Fotograma()
        {
            return hilo.Ejecutar(() =>
            {
                if (!enVivo) throw new CamaraException("vista_previa_inactiva", 409, "La vista previa de la cámara no está activa.");
                return Descargar();
            }, EsperaMs);
        }

        /// <summary>La fotografía es un fotograma de Live View a resolución de Live View: el disparo exige tarjeta SD.</summary>
        public byte[] Capturar(out string origen)
        {
            origen = "liveview";
            return Fotograma();
        }

        public void DetenerVistaPrevia()
        {
            try { hilo.Ejecutar(() => { Detener(); return true; }, EsperaMs); } catch (CamaraException) { }
        }

        // ---- Solo en el hilo del SDK ----

        private void IniciarSdk()
        {
            if (sdkIniciado) return;
            Comprobar(Edsdk.EdsInitializeSDK(), "EdsInitializeSDK");
            sdkIniciado = true;
        }

        private void TerminarSdk()
        {
            if (!sdkIniciado || camara != IntPtr.Zero) return;
            Edsdk.EdsTerminateSDK();
            sdkIniciado = false;
        }

        private string LeerModelo()
        {
            IntPtr buf = Marshal.AllocHGlobal(256);
            try
            {
                return Edsdk.EdsGetPropertyDataPtr(camara, PropProductName, 0, 256, buf) == 0 ? Marshal.PtrToStringAnsi(buf) : null;
            }
            finally { Marshal.FreeHGlobal(buf); }
        }

        /// <summary>Un fotograma JPEG del Live View; espera mientras el Live View arranca (EDS_ERR_OBJECT_NOTREADY).</summary>
        private byte[] Descargar()
        {
            DateTime limite = DateTime.UtcNow.AddSeconds(8);
            while (true)
            {
                Edsdk.EdsGetEvent();
                IntPtr flujo = IntPtr.Zero, evf = IntPtr.Zero;
                try
                {
                    Comprobar(Edsdk.EdsCreateMemoryStream(0, out flujo), "EdsCreateMemoryStream");
                    Comprobar(Edsdk.EdsCreateEvfImageRef(flujo, out evf), "EdsCreateEvfImageRef");
                    uint r = Edsdk.EdsDownloadEvfImage(camara, evf);
                    if (r == 0)
                    {
                        IntPtr p;
                        ulong largo;
                        Comprobar(Edsdk.EdsGetPointer(flujo, out p), "EdsGetPointer");
                        Comprobar(Edsdk.EdsGetLength(flujo, out largo), "EdsGetLength");
                        if (largo > 0 && largo < 20UL * 1024 * 1024)
                        {
                            var bytes = new byte[(int)largo];
                            Marshal.Copy(p, bytes, 0, bytes.Length);
                            if (bytes.Length > 3 && bytes[0] == 0xFF && bytes[1] == 0xD8) return bytes;
                        }
                    }
                    else if (r != ErrNoListo)
                    {
                        Comprobar(r, "EdsDownloadEvfImage");
                    }
                }
                finally
                {
                    if (evf != IntPtr.Zero) Edsdk.EdsRelease(evf);
                    if (flujo != IntPtr.Zero) Edsdk.EdsRelease(flujo);
                }
                if (DateTime.UtcNow > limite) throw new CamaraException("sin_fotograma", 504, "La cámara no entregó imagen de Live View a tiempo; compruebe que esté encendida y con el dial en P.");
                Thread.Sleep(150);
            }
        }

        /// <summary>Restaura los ajustes de Live View, cierra la sesión y termina el SDK. Nunca lanza.</summary>
        private void Detener()
        {
            if (camara != IntPtr.Zero)
            {
                try
                {
                    if (salidaOriginal.HasValue) { uint s = salidaOriginal.Value; Edsdk.EdsSetPropertyData(camara, PropEvfOutputDevice, 0, 4, ref s); }
                    if (modoOriginal.HasValue && modoOriginal.Value != 1) { uint m = modoOriginal.Value; Edsdk.EdsSetPropertyData(camara, PropEvfMode, 0, 4, ref m); }
                    Thread.Sleep(300);
                    Edsdk.EdsCloseSession(camara);
                }
                catch (Exception) { }
                Edsdk.EdsRelease(camara);
                camara = IntPtr.Zero;
            }
            if (lista != IntPtr.Zero) { Edsdk.EdsRelease(lista); lista = IntPtr.Zero; }
            salidaOriginal = null;
            modoOriginal = null;
            enVivo = false;
            if (sdkIniciado) { Edsdk.EdsTerminateSDK(); sdkIniciado = false; }
        }

        private static void Comprobar(uint codigo, string funcion)
        {
            if (codigo == 0) return;
            // EDS_ERR_DEVICE_NOT_FOUND (0x80) / COMM_DISCONNECTED (0xC0..): la cámara no está disponible.
            bool desconectada = codigo == 0x80 || codigo == 0xC0 || codigo == 0xC1 || codigo == 0x81 || codigo == 0xC7;
            throw new CamaraException(desconectada ? EstadoCamara.Desconectada : "error_camara", desconectada ? 503 : 502,
                funcion + " devolvió 0x" + codigo.ToString("X8") + (codigo == 0x81 ? " (cámara ocupada: cierre EOS Utility u otro programa)" : "") + ".");
        }
    }

    /// <summary>Firmas EDSDK (stdcall), las mismas usadas en las pruebas B3/B4 con EDSDK 3.8.20 x86.</summary>
    internal static class Edsdk
    {
        public static readonly string[] Funciones =
        {
            "EdsInitializeSDK", "EdsTerminateSDK", "EdsGetEvent", "EdsGetCameraList", "EdsGetChildCount", "EdsGetChildAtIndex",
            "EdsGetDeviceInfo", "EdsOpenSession", "EdsCloseSession", "EdsRelease", "EdsGetPropertyData", "EdsSetPropertyData",
            "EdsCreateMemoryStream", "EdsCreateEvfImageRef", "EdsDownloadEvfImage", "EdsGetPointer", "EdsGetLength",
        };

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
        public struct DeviceInfo
        {
            [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 256)] public string Puerto;
            [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 256)] public string Descripcion;
            public uint Subtipo;
            public uint Reservado;
        }

        [DllImport("kernel32", SetLastError = true, CharSet = CharSet.Unicode)] public static extern bool SetDllDirectory(string ruta);
        [DllImport("kernel32", SetLastError = true, CharSet = CharSet.Unicode)] public static extern IntPtr LoadLibrary(string ruta);
        [DllImport("kernel32", CharSet = CharSet.Ansi, ExactSpelling = true)] public static extern IntPtr GetProcAddress(IntPtr modulo, string nombre);

        [DllImport("EDSDK.dll")] public static extern uint EdsInitializeSDK();
        [DllImport("EDSDK.dll")] public static extern uint EdsTerminateSDK();
        [DllImport("EDSDK.dll")] public static extern uint EdsGetEvent();
        [DllImport("EDSDK.dll")] public static extern uint EdsGetCameraList(out IntPtr lista);
        [DllImport("EDSDK.dll")] public static extern uint EdsGetChildCount(IntPtr r, out int n);
        [DllImport("EDSDK.dll")] public static extern uint EdsGetChildAtIndex(IntPtr r, int i, out IntPtr hijo);
        [DllImport("EDSDK.dll")] public static extern uint EdsGetDeviceInfo(IntPtr camara, out DeviceInfo info);
        [DllImport("EDSDK.dll")] public static extern uint EdsOpenSession(IntPtr camara);
        [DllImport("EDSDK.dll")] public static extern uint EdsCloseSession(IntPtr camara);
        [DllImport("EDSDK.dll")] public static extern uint EdsRelease(IntPtr r);
        [DllImport("EDSDK.dll")] public static extern uint EdsGetPropertyData(IntPtr r, uint id, int p, int tam, out uint dato);
        [DllImport("EDSDK.dll", EntryPoint = "EdsGetPropertyData")] public static extern uint EdsGetPropertyDataPtr(IntPtr r, uint id, int p, int tam, IntPtr dato);
        [DllImport("EDSDK.dll")] public static extern uint EdsSetPropertyData(IntPtr r, uint id, int p, int tam, ref uint dato);
        [DllImport("EDSDK.dll")] public static extern uint EdsCreateMemoryStream(ulong tam, out IntPtr flujo);
        [DllImport("EDSDK.dll")] public static extern uint EdsCreateEvfImageRef(IntPtr flujo, out IntPtr evf);
        [DllImport("EDSDK.dll")] public static extern uint EdsDownloadEvfImage(IntPtr camara, IntPtr evf);
        [DllImport("EDSDK.dll")] public static extern uint EdsGetPointer(IntPtr flujo, out IntPtr p);
        [DllImport("EDSDK.dll")] public static extern uint EdsGetLength(IntPtr flujo, out ulong largo);
    }
}
