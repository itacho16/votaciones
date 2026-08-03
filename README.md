# Backend — Plataforma de Votaciones Escolares

Backend en PHP (PDO + `pgsql`) para conectar con la interfaz de votación y el panel administrador.

## Instalación

1. Crear la base de datos en PostgreSQL:
   ```bash
   createdb -U postgres votaciones_db
   ```
2. Ejecutar el esquema base (tablas `usuarios_admin`, `estudiantes`, `elecciones`, `cargos`, `candidatos`, `votos`):
   ```bash
   psql -U postgres -h localhost -d votaciones_db -f schema.sql
   ```
3. Ejecutar la migración adicional de miembros de mesa:
   ```bash
   psql -U postgres -h localhost -d votaciones_db -f migracion_miembros_mesa.sql
   ```
4. Ejecutar la migración adicional de auditoría (archivado de elecciones + bitácora):
   ```bash
   psql -U postgres -h localhost -d votaciones_db -f migracion_auditoria.sql
   ```
5. Ejecutar la migración de **multi-tenant** (separa los datos por colegio; si ya tenías datos de un solo colegio, quedan asignados a un colegio "por defecto" automáticamente):
   ```bash
   psql -U postgres -h localhost -d votaciones_db -f migracion_multitenant.sql
   ```
6. Copiar `.env.example` a `.env` y completar tus credenciales de PostgreSQL.
7. Instalar dependencias (PhpSpreadsheet para Excel, Dompdf para el acta en PDF):
   ```bash
   composer install
   ```
   Si ya habías corrido `composer install` antes de que se agregara Dompdf, vuelve a correrlo — instalará la dependencia nueva sin tocar lo demás.
8. Dar permisos de escritura a la carpeta de subida de imágenes:
   ```bash
   chmod -R 755 uploads/
   ```
9. Crear un colegio (tenant):
   ```bash
   php scripts/crear_colegio.php "IE 1234 San Martín" san-martin
   ```
10. Crear el primer usuario administrador de ese colegio (no existe registro por la web a propósito):
   ```bash
   php scripts/crear_admin.php coordinador@colegio.edu.pe "unaContraseñaSegura123" "Nombre Completo" san-martin
   ```
   El admin queda asociado a ese colegio. El login (`/auth/login.php`) sigue siendo solo email+password — no hace falta elegir colegio a mano, la cuenta ya lo tiene asociado, y todo lo que ese admin haga desde el panel (crear elecciones, candidatos, importar estudiantes, etc.) queda automáticamente aislado a su colegio.
11. Repite los pasos 9 y 10 por cada colegio adicional que quieras dar de alta.
12. (Opcional) Otorga acceso de **superadmin de plataforma** a una de tus cuentas de admin ya creadas, para poder ver todos los colegios y sus administradores desde `panel_superadmin.html`:
    ```bash
    psql -U postgres -h localhost -d votaciones_db -f migracion_superadmin_plataforma.sql
    php scripts/otorgar_superadmin_plataforma.php admin@colegio.edu.pe activar
    ```
    Esto es un concepto distinto del rol `superadmin` (que aplica solo dentro de un colegio): esta cuenta sigue entrando normal a `panel_admin.html` para su colegio, y además puede entrar a `panel_superadmin.html` para ver TODOS los colegios. No hay forma de otorgar esto desde la web, a propósito.
13. Apuntar tu servidor (Apache/Nginx/PHP built-in server) a esta carpeta como raíz del backend.
   Para pruebas rápidas:
   ```bash
   php -S localhost:8000
   ```

## Endpoints

### Públicos (usados por la interfaz de votación del estudiante)

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/login.php` | Body: `{ "token_acceso": "XXXX-XXXX" }`. Valida el token y devuelve la boleta (cargos + candidatos) si hay una elección activa y el estudiante no ha votado. |
| POST | `/api/votar.php` | Body: `{ "selecciones": { "id_cargo": id_candidato, ... } }`. Registra el voto usando el estudiante guardado en sesión (no confía en datos del cliente). |
| GET | `/api/resultados.php?id_eleccion=1` | Devuelve el conteo de votos por cargo y candidato, con porcentajes. |

### Autenticación (administrador)

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/auth/login.php` | Body: `{ "email": "...", "password": "..." }`. Valida contra `usuarios_admin` con `password_verify` y crea la sesión. |
| POST | `/auth/logout.php` | Cierra la sesión del administrador. |
| GET | `/auth/me.php` | Devuelve los datos del admin si hay sesión activa, o 401 si no. Usado por `panel_admin.html` al cargar. |

### Administrador (protegidos con `requireAdminAuth()`)

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/admin/candidato_guardar.php` | `multipart/form-data` con `nombre`, `descripcion`, `id_cargo`, y archivos `foto` / `logo`. Valida tipo (JPG/PNG/WEBP) y tamaño (máx. 3 MB) antes de guardar. |
| GET | `/admin/cargos_listar.php?id_eleccion=1` | Lista cargos + candidatos de una elección (para el panel de gestión). |
| POST | `/admin/estudiantes_importar.php` | `multipart/form-data` con archivo `archivo` (.xlsx). Columnas esperadas: código, nombres, apellidos, grado, sección. Genera un `token_acceso` único (global) por estudiante nuevo y omite duplicados por `codigo_matricula` **dentro del colegio del admin que importa** (dos colegios distintos pueden reusar el mismo código interno). |
| GET | `/admin/elecciones_listar.php` | Lista todas las elecciones con conteo de cargos/candidatos. |
| POST | `/admin/eleccion_crear.php` | Crea una elección junto con sus cargos, en una transacción. |
| POST | `/admin/eleccion_estado_actualizar.php` | Cambia el estado (`borrador`/`activa`/`cerrada`). No permite activar sin candidatos registrados. |
| POST | `/admin/miembros_mesa_guardar.php` | Reemplaza la lista completa de miembros de mesa de una elección. |
| GET | `/admin/miembros_mesa_listar.php?id_eleccion=1` | Lista los miembros de mesa registrados. |
| GET | `/admin/acta_pdf.php?id_eleccion=1` | Genera y descarga el Acta Electoral en PDF. Exige elección `cerrada` y al menos un Presidente + Secretario de mesa registrados. |
| GET | `/admin/eleccion_exportar.php?id_eleccion=1` | Descarga un JSON con el detalle completo de una elección cerrada (incluye qué estudiante votó por cada candidato), la marca como `archivada`, y registra la acción en `auditoria_log`. Solo `superadmin`. |
| GET | `/admin/auditoria_listar.php` | Lista la bitácora de auditoría (últimos 200 registros). Solo `superadmin`. |

### Plataforma (protegidos con `requirePlataformaAuth()`; solo cuentas con `es_superadmin_plataforma = TRUE`)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/plataforma/colegios_listar.php` | Todos los colegios registrados, con conteo de estudiantes/elecciones y la lista de administradores de cada uno. Usado por `panel_superadmin.html`. |
| POST | `/admin/eleccion_eliminar.php` | **Irreversible.** Borra una elección cerrada y todos sus datos (votos, cargos, candidatos, imágenes, miembros de mesa). Solo accesible para administradores con rol `superadmin`. |

## Decisiones de seguridad importantes

- **Doble voto**: se previene en tres capas — (1) `login.php` verifica si quedan cargos pendientes antes de mostrar la boleta, (2) `votar.php` valida que cargo/candidato correspondan entre sí, y (3) la restricción `UNIQUE (id_estudiante, id_cargo)` en la tabla `votos` es la barrera final a nivel de base de datos, incluso si alguien manipula las peticiones.
- **Identidad del votante**: `votar.php` nunca lee `id_estudiante` del cuerpo de la petición, siempre de `$_SESSION`, que se define únicamente tras validar el token en `login.php`.
- **Subida de imágenes**: el tipo de archivo se valida leyendo los bytes reales (`finfo`), no la extensión ni el `Content-Type` declarado por el navegador. Los nombres de archivo se aleatorizan al guardarlos.
- **Aislamiento multi-tenant (por colegio)**: cada tabla tiene su propia columna `id_colegio`. En el panel de administrador, ese valor SIEMPRE sale de la sesión (fijado en `auth/login.php` al iniciar sesión), nunca de un parámetro que mande el navegador — así ningún admin puede leer ni modificar datos de otro colegio adivinando un id numérico en la URL o el body. En el flujo del estudiante, el `id_colegio` se deriva del propio `token_acceso` (que es único a nivel global), y de ahí se acota la elección activa y la validación del voto.

## Pendiente por definir

- Endpoint para crear/cerrar elecciones y cargos desde el panel.
- Edición y eliminación de candidatos (los botones ya existen en la maqueta).
- CORS: si separas frontend y backend en dominios distintos, deberás configurar `Access-Control-Allow-Origin` a un dominio específico (no `*`) y `Access-Control-Allow-Credentials: true`, además de `session.cookie_samesite`.
