-- Migración: superadmin de plataforma
-- Ejecutar después de migracion_multitenant.sql
--
-- Uso:
--   psql -U postgres -h localhost -d votaciones_db -f migracion_superadmin_plataforma.sql
--
-- IMPORTANTE — esto es un concepto DISTINTO del rol 'superadmin' que ya
-- existe en usuarios_admin.rol:
--   - rol = 'superadmin'  -> puede ver auditoría y exportar/archivar
--                            elecciones, pero SOLO de su propio colegio.
--   - es_superadmin_plataforma = TRUE -> puede ver TODOS los colegios y
--                            sus administradores desde panel_superadmin.html.
--                            Pensado para ti como dueño/operador del
--                            sistema, no para el personal de un colegio.
--
-- Una cuenta puede tener ambas cosas, ninguna, o solo una.

BEGIN;

ALTER TABLE usuarios_admin
    ADD COLUMN IF NOT EXISTS es_superadmin_plataforma BOOLEAN NOT NULL DEFAULT FALSE;

COMMIT;
