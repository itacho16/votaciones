-- Migración adicional: archivado de elecciones + bitácora de auditoría
-- Ejecutar después del esquema original y de migracion_miembros_mesa.sql
--
-- Uso:
--   psql -U postgres -h localhost -d votaciones_db -f migracion_auditoria.sql

ALTER TABLE elecciones
    ADD COLUMN IF NOT EXISTS archivada BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS auditoria_log (
    id SERIAL PRIMARY KEY,
    id_admin INT REFERENCES usuarios_admin(id),
    accion VARCHAR(50) NOT NULL,
    id_eleccion INT, -- sin FK con CASCADE: el log debe sobrevivir aunque la elección referida cambie
    detalle TEXT,
    creado_en TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
