#!/bin/bash
# dev/deploy_ftp.sh — Sube archivos a producción por FTP con TLS explícito (igual que FileZilla).
#
# La contraseña NO va aquí: curl la lee de ~/.netrc (créalo tú, con permisos 600):
#   machine 50.62.222.52 login webmaster@naranjaymediahn.com password TU_CLAVE
#
# Uso:
#   dev/deploy_ftp.sh archivo1 archivo2 ...      → muestra la lista, pide confirmación y sube
#   dev/deploy_ftp.sh --probar                   → solo prueba la conexión (lista la carpeta remota)
#
# Nunca sube config.php, la carpeta dev/ ni archivos .sql. Archivos de uploads/ solo con DEPLOY_UPLOADS=1 (y solo si no existen).
set -euo pipefail

HOST="50.62.222.52"
# Carpeta del sitio vista desde el FTP (la que ves en FileZilla al entrar). Ajústala si es distinta.
REMOTO="${DEPLOY_REMOTO:-/public_html/facturacion.naranjaymediahn.com/}"
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
# El servidor (hosting de GoDaddy) presenta el certificado *.prod.phx3.secureserver.net, no uno para la IP.
# Se conecta a la IP pero se valida ese certificado con un nombre del comodín (sin desactivar la verificación TLS).
NOMBRE_TLS="ftp.prod.phx3.secureserver.net"

[ -f "$HOME/.netrc" ] || { echo "Falta ~/.netrc con la línea: machine $HOST login … password …"; exit 1; }
# curl busca la credencial por el nombre de la URL: copia temporal de ~/.netrc con ese nombre (solo tu usuario, se borra al salir)
NETRC_TMP="$(mktemp)"; chmod 600 "$NETRC_TMP"; trap 'rm -f "$NETRC_TMP"' EXIT
sed "s/machine[[:space:]]\{1,\}$HOST/machine $NOMBRE_TLS/" "$HOME/.netrc" > "$NETRC_TMP"
CURL=(curl --silent --show-error --fail --netrc-file "$NETRC_TMP" --ssl-reqd --ftp-pasv --connect-timeout 20
      --connect-to "$NOMBRE_TLS:21:$HOST:21")

if [ "${1:-}" = "--probar" ]; then
    echo "Conectando a $HOST, carpeta $REMOTO …"
    "${CURL[@]}" --list-only "ftp://$NOMBRE_TLS$REMOTO"
    exit 0
fi

[ $# -gt 0 ] || { echo "Indica los archivos a subir."; exit 1; }

archivos=()
for f in "$@"; do
    rel="${f#$RAIZ/}"
    case "$rel" in
        config.php|*/config.php|dev/*|*.sql) echo "  ✗ omitido (protegido): $rel"; continue ;;
        *uploads/*)
            # Comprobantes nuevos: solo con DEPLOY_UPLOADS=1 y nunca reemplaza uno que ya exista en el servidor
            if [ "${DEPLOY_UPLOADS:-}" != "1" ]; then echo "  ✗ omitido (protegido): $rel"; continue; fi
            if "${CURL[@]}" --head "ftp://$NOMBRE_TLS${REMOTO%/}/$rel" >/dev/null 2>&1; then echo "  ✗ ya existe en el servidor, no se reemplaza: $rel"; continue; fi ;;
    esac
    [ -f "$RAIZ/$rel" ] || { echo "  ✗ no existe: $rel"; exit 1; }
    archivos+=("$rel")
done
[ ${#archivos[@]} -gt 0 ] || { echo "Nada que subir."; exit 1; }

echo "Se subirán ${#archivos[@]} archivo(s) a $HOST:$REMOTO :"
printf '  • %s\n' "${archivos[@]}"
if [ "${DEPLOY_SI:-}" != "1" ]; then
    read -r -p "¿Subir? (s/N) " r
    [ "$r" = "s" ] || [ "$r" = "S" ] || { echo "Cancelado."; exit 1; }
fi

for rel in "${archivos[@]}"; do
    "${CURL[@]}" --ftp-create-dirs -T "$RAIZ/$rel" "ftp://$NOMBRE_TLS${REMOTO%/}/$rel" && echo "  ✓ $rel"
done
echo "Listo."
