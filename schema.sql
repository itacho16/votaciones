-- Esquema base — Sistema de Elecciones Escolares
--
-- NOTA: este archivo no venía en el proyecto original; fue reconstruido
-- a partir de las columnas y tipos usados realmente en el código PHP
-- (admin/*.php, api/*.php, auth/*.php). Si en algún momento encuentras
-- el script original, compáralo con este antes de usarlo en producción,
-- por si hay alguna diferencia menor (ej. longitudes de VARCHAR).
--
-- Orden de ejecución completo del proyecto:
--   1. schema.sql                    (este archivo)
--   2. migracion_miembros_mesa.sql
--   3. migracion_auditoria.sql
--   4. migracion_multitenant.sql
--
-- Uso:
--   psql -U postgres -h localhost -d votaciones_db -f schema.sql

BEGIN;

-- ============================================================
-- usuarios_admin: coordinadores/superadmins que usan panel_admin.html
-- ============================================================
CREATE TABLE usuarios_admin (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    -- 'superadmin' ve auditoría y puede exportar/archivar elecciones cerradas;
    -- 'coordinador' solo gestiona elecciones/candidatos/estudiantes del día a día.
    rol VARCHAR(20) NOT NULL DEFAULT 'coordinador' CHECK (rol IN ('superadmin', 'coordinador')),
    creado_en TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- estudiantes: votantes, importados desde Excel (admin/estudiantes_importar.php)
-- ============================================================
CREATE TABLE estudiantes (
    id SERIAL PRIMARY KEY,
    codigo_matricula VARCHAR(30) NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    grado VARCHAR(20),
    seccion VARCHAR(10),
    -- Identifica al estudiante en api/login.php; formato "XXXX-XXXX"
    -- generado por helpers/security.php::generarTokenAcceso().
    token_acceso VARCHAR(20) UNIQUE NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (codigo_matricula)
);

-- ============================================================
-- elecciones: procesos electorales creados desde admin/eleccion_crear.php
-- ============================================================
CREATE TABLE elecciones (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(200) NOT NULL,
    descripcion TEXT,
    fecha_inicio TIMESTAMPTZ NOT NULL,
    fecha_fin TIMESTAMPTZ NOT NULL,
    -- 'borrador' al crearse -> 'activa' cuando se abre a votación -> 'cerrada' al finalizar.
    estado VARCHAR(20) NOT NULL DEFAULT 'borrador' CHECK (estado IN ('borrador', 'activa', 'cerrada')),
    id_admin INT NOT NULL REFERENCES usuarios_admin(id),
    creado_en TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CHECK (fecha_fin > fecha_inicio)
);

-- ============================================================
-- cargos: puestos a elegir dentro de una elección (ej. "Alcalde Escolar")
-- ============================================================
CREATE TABLE cargos (
    id SERIAL PRIMARY KEY,
    id_eleccion INT NOT NULL REFERENCES elecciones(id) ON DELETE CASCADE,
    nombre VARCHAR(100) NOT NULL,
    orden INT NOT NULL DEFAULT 0
);

-- ============================================================
-- candidatos: postulantes a un cargo, con foto y logo de lista opcionales
-- ============================================================
CREATE TABLE candidatos (
    id SERIAL PRIMARY KEY,
    id_cargo INT NOT NULL REFERENCES cargos(id) ON DELETE CASCADE,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT,
    foto_url VARCHAR(255),
    logo_url VARCHAR(255),
    orden INT NOT NULL DEFAULT 0,
    creado_en TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- votos: un voto = un estudiante + un cargo + el candidato elegido.
-- La restricción UNIQUE es la última línea de defensa contra doble voto
-- (api/votar.php ya valida esto también a nivel de aplicación).
-- ============================================================
CREATE TABLE votos (
    id SERIAL PRIMARY KEY,
    id_estudiante INT NOT NULL REFERENCES estudiantes(id),
    id_cargo INT NOT NULL REFERENCES cargos(id),
    id_candidato INT NOT NULL REFERENCES candidatos(id),
    fecha_hora TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (id_estudiante, id_cargo)
);

-- ============================================================
-- Índices de apoyo para las consultas más frecuentes
-- ============================================================
CREATE INDEX idx_cargos_eleccion ON cargos (id_eleccion);
CREATE INDEX idx_candidatos_cargo ON candidatos (id_cargo);
CREATE INDEX idx_votos_cargo ON votos (id_cargo);
CREATE INDEX idx_votos_candidato ON votos (id_candidato);
CREATE INDEX idx_elecciones_estado_fechas ON elecciones (estado, fecha_inicio, fecha_fin);

COMMIT;
