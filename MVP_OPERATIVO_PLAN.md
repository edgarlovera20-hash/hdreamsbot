# HDreams Bot - MVP Operativo

## Objetivo

Convertir la app actual en una consola operativa de reclutamiento que permita:

- recibir leads multi-canal
- operar el pipeline completo
- agendar entrevistas
- dar seguimiento
- medir conversion y no-show

El MVP debe mejorar estas tres metricas primero:

- tiempo a primer contacto
- tasa de entrevista agendada
- tasa de asistencia a entrevista

## Alcance funcional

### Modulos

1. Inbox de leads
2. Ficha 360 del candidato
3. Pipeline operativo
4. Agenda de entrevistas
5. Plantillas de mensajes
6. Timeline y notas
7. Dashboard de conversion basico

### Flujos criticos

1. Llega lead
2. Se crea o actualiza candidato
3. Se asigna etapa inicial
4. Reclutador contacta o bot contacta
5. Se filtra por vacante
6. Se agenda entrevista
7. Se confirma o reagenda
8. Se marca asistencia o no-show
9. Se cierra como contratado o rechazado

## Modelo de datos

### Reutilizar tablas existentes

- `leads`
- `lead_scoring_ia`
- `lead_cola_prioridad`
- `secciones`
- `canales`

### Cambios propuestos sobre `leads`

Agregar:

- `assigned_recruiter_id INT NULL`
- `current_stage VARCHAR(50) NOT NULL DEFAULT 'nuevo_lead'`
- `source_detail VARCHAR(100) NULL`
- `last_inbound_at TIMESTAMP NULL`
- `last_outbound_at TIMESTAMP NULL`
- `next_action_at TIMESTAMP NULL`
- `next_action_type VARCHAR(50) NULL`
- `screening_status ENUM('pendiente','aprobado','rechazado') DEFAULT 'pendiente'`
- `interview_status ENUM('sin_agendar','agendada','confirmada','reagendada','realizada','no_show') DEFAULT 'sin_agendar'`

Indices:

- `INDEX idx_stage_priority (empresa_id, current_stage, prioridad)`
- `INDEX idx_recruiter_stage (assigned_recruiter_id, current_stage)`
- `INDEX idx_next_action (next_action_at)`

### Tablas nuevas

#### `recruiters`

```sql
CREATE TABLE recruiters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(120) NULL,
  telefono VARCHAR(20) NULL,
  activo TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
);
```

#### `lead_events`

Timeline auditable de todo lo que pasa con un lead.

```sql
CREATE TABLE lead_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  empresa_id INT NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  event_label VARCHAR(100) NOT NULL,
  payload JSON NULL,
  actor_type ENUM('system','bot','recruiter','candidate') NOT NULL DEFAULT 'system',
  actor_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  INDEX idx_lead_created (lead_id, created_at DESC),
  INDEX idx_empresa_type (empresa_id, event_type)
);
```

#### `lead_notes`

```sql
CREATE TABLE lead_notes (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  recruiter_id INT NOT NULL,
  note TEXT NOT NULL,
  is_pinned TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE CASCADE,
  INDEX idx_lead_notes (lead_id, created_at DESC)
);
```

#### `interviews`

```sql
CREATE TABLE interviews (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  empresa_id INT NOT NULL,
  recruiter_id INT NULL,
  office_location VARCHAR(150) NOT NULL,
  interview_date DATE NOT NULL,
  interview_time TIME NOT NULL,
  status ENUM('agendada','confirmada','reagendada','realizada','cancelada','no_show') DEFAULT 'agendada',
  confirmation_channel ENUM('whatsapp','messenger','instagram','facebook','telegram','manual') DEFAULT 'manual',
  reminder_24h_sent_at TIMESTAMP NULL,
  reminder_2h_sent_at TIMESTAMP NULL,
  attended_at TIMESTAMP NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_slot (empresa_id, interview_date, interview_time, recruiter_id),
  INDEX idx_status_date (status, interview_date),
  INDEX idx_lead_interviews (lead_id, created_at DESC)
);
```

#### `interview_slots`

```sql
CREATE TABLE interview_slots (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  recruiter_id INT NULL,
  slot_date DATE NOT NULL,
  slot_time TIME NOT NULL,
  capacity INT NOT NULL DEFAULT 1,
  reserved INT NOT NULL DEFAULT 0,
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_slot_capacity (empresa_id, recruiter_id, slot_date, slot_time),
  INDEX idx_slot_lookup (slot_date, slot_time, active)
);
```

#### `message_templates`

```sql
CREATE TABLE message_templates (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  seccion_id INT NULL,
  channel ENUM('whatsapp','messenger','instagram','facebook','telegram','all') DEFAULT 'all',
  stage_key VARCHAR(50) NOT NULL,
  template_name VARCHAR(100) NOT NULL,
  content TEXT NOT NULL,
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (seccion_id) REFERENCES secciones(id) ON DELETE SET NULL,
  INDEX idx_template_lookup (empresa_id, seccion_id, stage_key, channel, active)
);
```

#### `lead_assignments`

```sql
CREATE TABLE lead_assignments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  recruiter_id INT NOT NULL,
  assigned_by INT NULL,
  reason VARCHAR(100) NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  released_at TIMESTAMP NULL,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE CASCADE,
  INDEX idx_lead_assignment (lead_id, assigned_at DESC),
  INDEX idx_recruiter_assignment (recruiter_id, assigned_at DESC)
);
```

## Etapas del pipeline

Usar claves estables en backend y etiquetas legibles en frontend.

```text
nuevo_lead
contactado
interesado
filtrado
calificado
entrevista_agendada
confirmado
reagendar
no_asistio
en_capacitacion
contratado
rechazado
```

Reglas MVP:

- `nuevo_lead` al entrar
- `contactado` al primer mensaje saliente
- `interesado` si el candidato responde positivamente
- `filtrado` cuando completa preguntas base
- `calificado` si cumple edad, zona y disponibilidad
- `entrevista_agendada` al reservar slot
- `confirmado` al confirmar asistencia
- `reagendar` si cambia fecha
- `no_asistio` si la entrevista no se marca realizada
- `en_capacitacion` manual
- `contratado` manual
- `rechazado` manual o por filtro

## API propuesta

### Leads

#### `GET /api/leads`

Lista con filtros operativos:

- `empresa_id`
- `stage`
- `assigned_recruiter_id`
- `prioridad`
- `interview_status`
- `canal`
- `q`

Respuesta:

```json
{
  "items": [],
  "total": 0,
  "page": 1,
  "per_page": 50
}
```

#### `GET /api/leads/{id}`

Detalle 360 del lead:

- lead base
- scoring
- entrevista activa
- ultimos eventos
- notas
- tags

#### `PATCH /api/leads/{id}`

Actualiza:

- `current_stage`
- `assigned_recruiter_id`
- `prioridad`
- `screening_status`
- `next_action_at`
- `next_action_type`

#### `POST /api/leads/{id}/notes`

Crear nota interna.

#### `GET /api/leads/{id}/events`

Timeline paginado.

#### `POST /api/leads/{id}/assign`

Asigna recruiter y registra evento.

#### `POST /api/leads/{id}/message`

Envia mensaje por canal activo y registra evento.

Body:

```json
{
  "template_id": 12,
  "content": "texto final renderizado"
}
```

### Pipeline

#### `GET /api/pipeline/summary?empresa_id=1`

Conteo por etapa, prioridad y recruiter.

#### `POST /api/pipeline/move`

Mover lead de etapa.

```json
{
  "lead_id": 10,
  "to_stage": "calificado",
  "reason": "cumple filtro"
}
```

### Entrevistas

#### `GET /api/interviews?empresa_id=1&date=2026-06-19`

Agenda del dia.

#### `GET /api/interview-slots?empresa_id=1&date=2026-06-19`

Slots disponibles.

#### `POST /api/interviews`

Agenda entrevista.

```json
{
  "lead_id": 10,
  "recruiter_id": 2,
  "slot_id": 15,
  "office_location": "Av. Tlahuac 3632 Int. A301"
}
```

#### `PATCH /api/interviews/{id}`

Actualizar `status`, reagendar, notas.

#### `POST /api/interviews/{id}/confirm`

Confirma asistencia.

#### `POST /api/interviews/{id}/no-show`

Marca no asistencia y devuelve slots sugeridos.

### Templates

#### `GET /api/templates?empresa_id=1&stage=contactado&channel=whatsapp`

#### `POST /api/templates`

#### `PATCH /api/templates/{id}`

### Flow

Reusar:

- `GET /api/flow`
- `GET /api/flow/funnel`

Agregar:

- `GET /api/flow/templates`
- `GET /api/flow/faq`

## Estructura frontend propuesta

### Rutas

```text
/
/leads
/leads/:id
/pipeline
/agenda
/templates
/flow
/stats
```

### Pantallas

#### Dashboard

- KPIs principales
- SLA vencidos
- entrevistas del dia
- leads urgentes
- no-show rate

#### Leads

Tabla operativa con filtros rapidos:

- etapa
- recruiter
- vacante
- canal
- prioridad
- entrevista

#### Lead Detail

Layout de 3 columnas:

- columna 1: datos del lead, estado, score, acciones
- columna 2: timeline de eventos y notas
- columna 3: entrevista, templates, siguiente accion

#### Pipeline

Vista kanban por etapa con drag and drop.

#### Agenda

- agenda diaria
- slots disponibles
- confirmados
- por confirmar
- no-show

#### Templates

- editor por etapa, vacante y canal
- preview con variables

### Componentes nuevos

```text
components/
  leads/
    LeadTable.jsx
    LeadFilters.jsx
    LeadDrawer.jsx
    LeadTimeline.jsx
    LeadNotes.jsx
    LeadActions.jsx
  pipeline/
    PipelineBoard.jsx
    PipelineColumn.jsx
    PipelineCard.jsx
  agenda/
    InterviewCalendar.jsx
    SlotPicker.jsx
    InterviewList.jsx
  templates/
    TemplateEditor.jsx
    VariableChips.jsx
```

## Contratos de UI

### Lead card

Debe mostrar:

- nombre
- vacante
- etapa
- prioridad
- ultimo contacto
- siguiente accion
- entrevista si existe

### Timeline event

Tipos minimos:

- `lead_created`
- `message_inbound`
- `message_outbound`
- `stage_changed`
- `score_updated`
- `interview_scheduled`
- `interview_confirmed`
- `interview_rescheduled`
- `interview_no_show`
- `note_added`

## Reglas de negocio MVP

### Filtro minimo de calificacion

Calificar si:

- edad dentro del rango de vacante
- disponibilidad inmediata positiva
- ciudad valida o razonablemente operable

Rechazar automatico si:

- edad por debajo del minimo

### Agenda

- un slot no puede exceder `capacity`
- reagendar libera el slot anterior
- confirmar no cambia fecha, solo estado

### Eventos

Todo cambio relevante escribe en `lead_events`.

## Orden recomendado de implementacion

### Fase 1

1. migraciones nuevas
2. `lead_events`
3. `interviews`
4. endpoint `GET /api/leads/{id}`
5. endpoint `POST /api/leads/{id}/notes`

### Fase 2

1. vista detalle de lead
2. agenda y slots
3. agendamiento
4. confirmacion y no-show

### Fase 3

1. pipeline board
2. templates
3. mensajes salientes desde app
4. dashboard de conversion

## Riesgos tecnicos actuales

- SQL concatenado en controllers
- router PHP simple sin capa transaccional
- falta de colas reales para recordatorios
- estados mezclados entre `estado`, `prioridad` y futura `current_stage`

## Decision tecnica recomendada

Para no romper el MVP actual:

- mantener `estado` como compatibilidad
- introducir `current_stage` como nueva fuente operativa
- sincronizar ambos durante transicion

Despues:

- migrar pantallas y KPIs a `current_stage`

