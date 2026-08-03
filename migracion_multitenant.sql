-- Migración: Multi-tenant (separación de datos por colegio)
-- Ejecutar después del esquema original y de las migraciones anteriores
-- (migracion_miembros_mesa.sql, migracion_auditoria.sql).
--
-- Uso:
--   psql -U postgres -h localhost -d votaciones_db -f migracion_multitenant.sql
--
-- Estrategia: una sola base de datos, con columna id_colegio en cada tabla
-- (incluidas las tablas "hijas" como cargos/candidatos/votos, de forma
-- denormalizada) para poder filtrar directo sin depender de joins anidados
-- y evitar fugas de datos entre colegios por error humano en una query.

BEGIN;

-- 1. Tabla de colegios (tenants)
CREATE TABLE IF NOT EXISTS colegios (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    slug VARCHAR(60) UNIQUE NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 2. Colegio por defecto: aquí "aterrizan" todos los datos que ya existían
--    antes de esta migración (single-tenant -> multi-tenant).
INSERT INTO colegios (id, nombre, slug)
VALUES (1, 'Colegio por defecto', 'default')
ON CONFLICT (id) DO NOTHING;

-- Evita que el próximo INSERT sin id explícito choque con el id=1 que
-- acabamos de insertar a mano.
SELECT setval('colegios_id_seq', (SELECT MAX(id) FROM colegios));

-- 3. Agregar id_colegio a cada tabla (nullable primero, para poder rellenar
--    los datos existentes antes de exigir NOT NULL).
ALTER TABLE usuarios_admin ADD COLUMN IF NOT EXISTS id_colegio INT;
ALTER TABLE estudiantes    ADD COLUMN IF NOT EXISTS id_colegio INT;
ALTER TABLE elecciones     ADD COLUMN IF NOT EXISTS id_colegio INT;
ALTER TABLE cargos         ADD COLUMN IF NOT EXISTS id_colegio INT;
ALTER TABLE candidatos     ADD COLUMN IF NOT EXISTS id_colegio INT;
ALTER TABLE votos          ADD COLUMN IF NOT EXISTS id_colegio INT;
ALTER TABLE miembros_mesa  ADD COLUMN IF NOT EXISTS id_colegio INT;
ALTER TABLE auditoria_log  ADD COLUMN IF NOT EXISTS id_colegio INT;

-- 4. Backfill: todo lo existente pertenece al colegio por defecto (id=1).
UPDATE usuarios_admin SET id_colegio = 1 WHERE id_colegio IS NULL;
UPDATE estudiantes    SET id_colegio = 1 WHERE id_colegio IS NULL;
UPDATE elecciones     SET id_colegio = 1 WHERE id_colegio IS NULL;

UPDATE cargos c SET id_colegio = e.id_colegio
    FROM elecciones e WHERE c.id_eleccion = e.id AND c.id_colegio IS NULL;

UPDATE candidatos ca SET id_colegio = c.id_colegio
    FROM cargos c WHERE ca.id_cargo = c.id AND ca.id_colegio IS NULL;

UPDATE votos v SET id_colegio = c.id_colegio
    FROM cargos c WHERE v.id_cargo = c.id AND v.id_colegio IS NULL;

UPDATE miembros_mesa m SET id_colegio = e.id_colegio
    FROM elecciones e WHERE m.id_eleccion = e.id AND m.id_colegio IS NULL;

UPDATE auditoria_log SET id_colegio = 1 WHERE id_colegio IS NULL;

-- 5. Ahora que no quedan NULLs, exigir NOT NULL + FK + índices.
ALTER TABLE usuarios_admin
    ALTER COLUMN id_colegio SET NOT NULL,
    ADD CONSTRAINT fk_usuarios_admin_colegio FOREIGN KEY (id_colegio) REFERENCES colegios(id);

ALTER TABLE estudiantes
    ALTER COLUMN id_colegio SET NOT NULL,
    ADD CONSTRAINT fk_estudiantes_colegio FOREIGN KEY (id_colegio) REFERENCES colegios(id);

ALTER TABLE elecciones
    ALTER COLUMN id_colegio SET NOT NULL,
    ADD CONSTRAINT fk_elecciones_colegio FOREIGN KEY (id_colegio) REFERENCES colegios(id);

ALTER TABLE cargos
    ALTER COLUMN id_colegio SET NOT NULL,
    ADD CONSTRAINT fk_cargos_colegio FOREIGN KEY (id_colegio) REFERENCES colegios(id);

ALTER TABLE candidatos
    ALTER COLUMN id_colegio SET NOT NULL,
    ADD CONSTRAINT fk_candidatos_colegio FOREIGN KEY (id_colegio) REFERENCES colegios(id);

ALTER TABLE votos
    ALTER COLUMN id_colegio SET NOT NULL,
    ADD CONSTRAINT fk_votos_colegio FOREIGN KEY (id_colegio) REFERENCES colegios(id);

ALTER TABLE miembros_mesa
    ALTER COLUMN id_colegio SET NOT NULL,
    ADD CONSTRAINT fk_miembros_mesa_colegio FOREIGN KEY (id_colegio) REFERENCES colegios(id);

-- auditoria_log se deja nullable a propósito (igual que id_eleccion): el log
-- debe sobrevivir aunque algo cambie, sin FK con CASCADE.

-- 6. Índices compuestos: casi toda query de la app ahora empieza por
--    "WHERE id_colegio = ... AND <lo que ya filtraba antes>".
CREATE INDEX IF NOT EXISTS idx_estudiantes_colegio ON estudiantes (id_colegio);
CREATE INDEX IF NOT EXISTS idx_elecciones_colegio ON elecciones (id_colegio);
CREATE INDEX IF NOT EXISTS idx_cargos_colegio_eleccion ON cargos (id_colegio, id_eleccion);
CREATE INDEX IF NOT EXISTS idx_candidatos_colegio_cargo ON candidatos (id_colegio, id_cargo);
CREATE INDEX IF NOT EXISTS idx_votos_colegio ON votos (id_colegio);
CREATE INDEX IF NOT EXISTS idx_miembros_mesa_colegio ON miembros_mesa (id_colegio, id_eleccion);
CREATE INDEX IF NOT EXISTS idx_auditoria_log_colegio ON auditoria_log (id_colegio);

-- NOTA sobre unicidad global vs. por colegio:
--   - usuarios_admin.email: se deja ÚNICA GLOBAL (no por colegio). Así el
--     login de administrador sigue siendo solo email+password, sin pedir
--     seleccionar colegio: la cuenta ya "sabe" a qué colegio pertenece.
--   - estudiantes.token_acceso: se deja ÚNICO GLOBAL, porque api/login.php
--     identifica al estudiante (y de ahí su colegio) SOLO con el token,
--     sin ningún otro dato de contexto.
--   - estudiantes.codigo_matricula: pasa a ser único POR COLEGIO (dos
--     colegios pueden tener, cada uno, un estudiante con código "0001").
--     Si tu esquema original tenía UNIQUE global en esta columna, quítalo
--     y usa el índice compuesto de abajo en su lugar:
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'estudiantes_codigo_matricula_key'
    ) THEN
        ALTER TABLE estudiantes DROP CONSTRAINT estudiantes_codigo_matricula_key;
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uq_estudiantes_colegio_codigo
    ON estudiantes (id_colegio, codigo_matricula);

COMMIT;
