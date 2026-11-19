using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.IO;
using System.Linq;

namespace AgenteEstacion
{
    /// <summary>Estado de la cámara tal como lo puede comprobar el adaptador.</summary>
    public static class EstadoCamara
    {
        public const string SdkNoInstalado = "sdk_no_instalado";
        public const string Desconectada = "desconectada";
        public const string Conectada = "conectada";
    }

    public class CamaraException : Exception
    {
        public readonly string Codigo;
        public readonly int Http;

        public CamaraException(string codigo, int http, string mensaje) : base(mensaje)
        {
            Codigo = codigo;
            Http = http;
        }
    }

    /// <summary>
    /// Contrato de un adaptador de cámara. Las imágenes se devuelven en memoria (JPEG) y nunca se escriben a disco.
    /// Punto de integración pendiente: un adaptador sobre el EDSDK OFICIAL de Canon, cuando se obtenga y se confirme
    /// la compatibilidad de la EOS Rebel T6. Ver agente-estacion/README.md.
    /// </summary>
    public interface ICamara
    {
        string Nombre { get; }
        bool EsSimulacion { get; }
        string Estado();
        string Modelo();
        void IniciarVistaPrevia();
        byte[] Fotograma();
        /// <summary>Devuelve la fotografía y su origen ("liveview" o "disparo").</summary>
        byte[] Capturar(out string origen);
        void DetenerVistaPrevia();
    }

    /// <summary>Adaptador por defecto: no hay SDK de Canon verificado instalado, así que no se finge ninguna conexión.</summary>
    public class CamaraSinSdk : ICamara
    {
        public string Nombre { get { return "ninguno"; } }
        public bool EsSimulacion { get { return false; } }
        public string Estado() { return EstadoCamara.SdkNoInstalado; }
        public string Modelo() { return null; }
        public void IniciarVistaPrevia() { throw SinSdk(); }
        public byte[] Fotograma() { throw SinSdk(); }
        public byte[] Capturar(out string origen) { origen = null; throw SinSdk(); }
        public void DetenerVistaPrevia() { }

        private static CamaraException SinSdk()
        {
            return new CamaraException(EstadoCamara.SdkNoInstalado, 503, "No hay un SDK de Canon verificado instalado en el agente.");
        }
    }

    /// <summary>
    /// SIMULACIÓN para pruebas sin cámara física. Solo se activa con --simulado. Cada imagen lleva la leyenda
    /// "SIMULACIÓN" y el estado del agente lo declara: nunca debe tomarse como una captura real.
    /// Modos: conectada (por defecto), desconectada, falla-captura.
    /// </summary>
    public class CamaraSimulada : ICamara
    {
        private readonly string modo;
        private bool vistaPrevia;
        private int numero;

        public CamaraSimulada(string modo)
        {
            string[] validos = { "conectada", "desconectada", "falla-captura" };
            this.modo = validos.Contains(modo) ? modo : "conectada";
        }

        public string Nombre { get { return "simulado"; } }
        public bool EsSimulacion { get { return true; } }
        public string Estado() { return modo == "desconectada" ? EstadoCamara.Desconectada : EstadoCamara.Conectada; }
        public string Modelo() { return modo == "desconectada" ? null : "SIMULACIÓN (sin cámara física)"; }

        public void IniciarVistaPrevia()
        {
            ExigirConexion();
            vistaPrevia = true;
        }

        public byte[] Fotograma()
        {
            ExigirConexion();
            if (!vistaPrevia) throw new CamaraException("vista_previa_inactiva", 409, "La vista previa no está activa.");
            return Imagen("Vista previa");
        }

        public byte[] Capturar(out string origen)
        {
            ExigirConexion();
            if (modo == "falla-captura") throw new CamaraException("error_camara", 502, "SIMULACIÓN: la cámara no entregó la imagen.");
            origen = "liveview";
            return Imagen("Captura");
        }

        public void DetenerVistaPrevia() { vistaPrevia = false; }

        private void ExigirConexion()
        {
            if (modo == "desconectada") throw new CamaraException(EstadoCamara.Desconectada, 503, "SIMULACIÓN: la cámara está desconectada.");
        }

        // Mismo tamaño que el fotograma de Live View de la T6 (1056 × 704) para ejercitar el recorte 3:4.
        private byte[] Imagen(string tipo)
        {
            numero++;
            using (var bmp = new Bitmap(1056, 704))
            using (var g = Graphics.FromImage(bmp))
            using (var fondo = new LinearGradientBrush(new Rectangle(0, 0, 1056, 704), Color.SlateGray, Color.Gainsboro, 35f))
            using (var fuente = new Font("Arial", 34, FontStyle.Bold))
            using (var fuentePequena = new Font("Arial", 18))
            using (var ms = new MemoryStream())
            {
                g.FillRectangle(fondo, 0, 0, 1056, 704);
                g.FillEllipse(Brushes.DimGray, 390, 140, 276, 360);
                g.DrawString("SIMULACIÓN", fuente, Brushes.DarkRed, 400, 40);
                g.DrawString("NO ES UNA FOTOGRAFÍA REAL", fuentePequena, Brushes.DarkRed, 380, 600);
                g.DrawString(tipo + " #" + numero, fuentePequena, Brushes.Black, 20, 660);
                bmp.Save(ms, ImageFormat.Jpeg);
                return ms.ToArray();
            }
        }
    }
}
