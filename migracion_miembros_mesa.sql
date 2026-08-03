-- Migración adicional: miembros de mesa electoral
-- Ejecutar después del esquema original (elecciones, cargos, candidatos, etc.)
--
-- Uso:
--   psql -U postgres -h localhost -d votaciones_db -f migracion_miembros_mesa.sql

CREATE TABLE IF NOT EXISTS miembros_mesa (
    id SERIAL PRIMARY KEY,
    id_eleccion INT NOT NULL REFERENCES elecciones(id) ON DELETE CASCADE,
    nombre_completo VARCHAR(150) NOT NULL,
    cargo_mesa VARCHAR(30) NOT NULL CHECK (cargo_mesa IN ('Presidente', 'Secretario', 'Miembro')),
    documento_identidad VARCHAR(20),
    orden INT DEFAULT 0,
    creado_en TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
