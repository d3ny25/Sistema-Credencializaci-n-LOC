using System;
using System.Collections.Generic;
using System.Security.Cryptography;
using System.Text;
using System.Web.Script.Serialization;

namespace AgenteEstacion
{
    public struct TicketValido
    {
        public long Elemento;
        public string Categoria;
        /// <summary>Nonce del ticket; en impresión es también el identificador del documento en la cola.</summary>
        public string Nonce;
    }

    /// <summary>
    /// Verifica los tickets que emite Laravel (App\Support\CapturaTicket): base64url(carga) "." base64url(HMAC-SHA256).
    /// Cada ticket se acepta una sola vez; los usados se recuerdan en memoria hasta que caducan.
    /// </summary>
    public class VerificadorTicket
    {
        private const int ToleranciaRelojSegundos = 30;
        private readonly byte[] secreto;
        private readonly Dictionary<string, long> usados = new Dictionary<string, long>();
        private readonly object candado = new object();

        public VerificadorTicket(string secretoEstacion)
        {
            secreto = Encoding.UTF8.GetBytes(secretoEstacion);
        }

        /// <summary>
        /// Devuelve el elemento y la categoría si el ticket es válido y su categoría está entre las permitidas para el
        /// dispositivo; si no, lanza CamaraException con el motivo.
        /// </summary>
        public TicketValido Consumir(string ticket, long ahora, string[] categoriasPermitidas)
        {
            string[] partes = (ticket ?? "").Split('.');
            if (partes.Length != 2) throw Invalido("El ticket de captura no es válido.");

            byte[] esperado;
            using (var hmac = new HMACSHA256(secreto))
            {
                esperado = hmac.ComputeHash(Encoding.ASCII.GetBytes(partes[0]));
            }
            byte[] recibido = DesdeB64(partes[1]);
            if (recibido == null || !IgualesEnTiempoConstante(esperado, recibido)) throw Invalido("El ticket de captura no es válido.");

            Dictionary<string, object> datos;
            try
            {
                datos = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(Encoding.UTF8.GetString(DesdeB64(partes[0])));
            }
            catch (Exception)
            {
                throw Invalido("El ticket de captura no es válido.");
            }

            if (Numero(datos, "v") != 1 || !datos.ContainsKey("n") || !(datos["n"] is string))
            {
                throw Invalido("El ticket de captura no es válido.");
            }
            object categoria;
            if (!datos.TryGetValue("c", out categoria) || Array.IndexOf(categoriasPermitidas, categoria as string) < 0)
            {
                throw Invalido("El ticket no autoriza una captura con este dispositivo.");
            }

            long expira = Numero(datos, "x");
            if (expira + ToleranciaRelojSegundos < ahora) throw new CamaraException("ticket_expirado", 401, "El ticket de captura expiró; inicie la captura de nuevo.");

            string nonce = (string)datos["n"];
            lock (candado)
            {
                var vencidos = new List<string>();
                foreach (var par in usados) if (par.Value < ahora) vencidos.Add(par.Key);
                foreach (var clave in vencidos) usados.Remove(clave);

                if (usados.ContainsKey(nonce)) throw new CamaraException("ticket_usado", 401, "Este ticket de captura ya se utilizó.");
                usados[nonce] = expira + ToleranciaRelojSegundos;
            }

            return new TicketValido { Elemento = Numero(datos, "e"), Categoria = (string)categoria, Nonce = nonce };
        }

        private static long Numero(Dictionary<string, object> datos, string clave)
        {
            object valor;
            if (!datos.TryGetValue(clave, out valor) || valor == null) return -1;
            try { return Convert.ToInt64(valor); } catch (Exception) { return -1; }
        }

        private static CamaraException Invalido(string mensaje)
        {
            return new CamaraException("ticket_invalido", 401, mensaje);
        }

        public static byte[] DesdeB64(string texto)
        {
            if (string.IsNullOrEmpty(texto)) return null;
            string s = texto.Replace('-', '+').Replace('_', '/');
            switch (s.Length % 4) { case 2: s += "=="; break; case 3: s += "="; break; case 1: return null; }
            try { return Convert.FromBase64String(s); } catch (FormatException) { return null; }
        }

        private static bool IgualesEnTiempoConstante(byte[] a, byte[] b)
        {
            if (a.Length != b.Length) return false;
            int diferencia = 0;
            for (int i = 0; i < a.Length; i++) diferencia |= a[i] ^ b[i];
            return diferencia == 0;
        }
    }
}
