# Guard patterns — input direto para o Agente 4

Convenções abaixo espelham `includes/tenancy.php` (nomes, fail-closed via
`tenancyDegradeAllowed()`, `isMultiTenant()` como master switch que retorna
`['', []]` dormente em N=1). Onde este doc disser "igual a X", copie a forma da
função citada, trocando apenas tabela/coluna. Todos os guards negam em erro
inesperado e só perdoam schema ausente (instalação part-migrada).

Regra de escrita que vale para os três: **leitura usa o filtro do viewer;
escrita usa `getActiveTenantId()` (UMA empresa, nunca o modo "all")** — ver
`ticketTenantFilter(..., $forceSingle)` e `activeTenantFilter()`.

## 1. departments — isolado, NOT NULL (igual a `analystCanAccessDomain` + `activeTenantFilter`)

Sem tratamento de NULL no caminho feliz (a coluna é NOT NULL pós-003); o NULL é
tratado como Default-owned **apenas** como defesa para instalação part-migrada.

```php
function analystCanAccessDepartment(PDO $conn, int $analystId, $departmentId): bool {
    if (!isMultiTenant($conn)) return true;
    $departmentId = (int)$departmentId;
    if ($departmentId <= 0) return false;
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM departments WHERE id = ?");
        $stmt->execute([$departmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;                       // id desconhecido → deny
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        return analystCanAccessTenant($conn, $analystId, $tid);
    } catch (Exception $e) {
        return tenancyDegradeAllowed($e);               // só missing-schema perdoa
    }
}

function departmentTenantFilter(PDO $conn, int $analystId, string $alias = 'd'): array {
    if (!isMultiTenant($conn)) return ['', []];
    $qualified = $alias === '' ? 'tenant_id' : "$alias.tenant_id";
    $active  = getActiveTenantId($conn, $analystId);
    $default = getDefaultTenantId($conn);
    if ($active === $default) {
        return [" AND ($qualified = ? OR $qualified IS NULL)", [$active]];
    }
    return [" AND $qualified = ?", [$active]];
}
```

Gatear com este guard todo endpoint by-id/mutação endereçado por department id, e
os joins que atravessam a fronteira: `tickets.department_id`,
`sla_notification_rules.department_id`, `department_teams`.

⚠️ **Caveat free-text (para o Agente 4 não perder):** `users.department`,
`manager_grants.target_value` e `report_pack_shares.target_value` guardam o NOME,
não o id — e o unique agora é por (tenant, nome), então "HR" pode existir em duas
empresas. Ao resolver nome→id, escopar pelo tenant do viewer; em empate, deny
(ambiguity fails closed), nunca "primeiro match".

## 2. sla_notification_rules — NULLABLE, NULL=global

Leitura: regra global (NULL) vale para todas as empresas; regra set vale para a
sua. Escrita de linha **global exige acesso a todos os tenants**
(`analystHasAllTenantAccess()`), porque cria efeito cross-company.

```php
function analystCanAccessSlaRule(PDO $conn, int $analystId, $ruleId): bool {
    if (!isMultiTenant($conn)) return true;
    $ruleId = (int)$ruleId;
    if ($ruleId <= 0) return false;
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM sla_notification_rules WHERE id = ?");
        $stmt->execute([$ruleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        if ($row['tenant_id'] === null) return true;    // global: gate é capability, não tenant
        return analystCanAccessTenant($conn, $analystId, (int)$row['tenant_id']);
    } catch (Exception $e) {
        return tenancyDegradeAllowed($e);
    }
}

function slaRuleTenantFilter(PDO $conn, int $analystId, string $alias = 'r'): array {
    if (!isMultiTenant($conn)) return ['', []];
    // DIFERENTE de activeTenantFilter de propósito: global (NULL) aparece em
    // TODA empresa ativa, não só sob Default.
    $qualified = $alias === '' ? 'tenant_id' : "$alias.tenant_id";
    return [" AND ($qualified = ? OR $qualified IS NULL)", [getActiveTenantId($conn, $analystId)]];
}

function analystCanWriteSlaRule(PDO $conn, int $analystId, $tenantId): bool {
    if (!isMultiTenant($conn)) return true;
    if ($tenantId === null || $tenantId === '' || (int)$tenantId <= 0) {
        return analystHasAllTenantAccess($conn, $analystId);  // global afeta todos
    }
    return analystCanAccessTenant($conn, $analystId, (int)$tenantId);
}
```

**Cron sem usuário** (`cron/sla_breach_check.php`, contexto sistema): não usa gates
de analista; para cada ticket avalia `regra.global OU regra.tenant ==
ticket.tenant` e carimba `sla_notifications_sent.tenant_id = ticket.tenant_id`
(nunca o tenant da regra — o log pertence ao ticket).

## 3. sla_calendars — isolado, NOT NULL (igual a departments, item 1)

Mesmo template do item 1, trocando a tabela; mais o gate das filhas via pai
(igual ao aviso de writes de comments/subtasks em `analystCanAccessTask()` —
gatear o filho pelo id do filho NUNCA basta quando o filho é endereçável):

```php
function analystCanAccessSlaCalendar(PDO $conn, int $analystId, $calendarId): bool {
    if (!isMultiTenant($conn)) return true;
    $calendarId = (int)$calendarId;
    if ($calendarId <= 0) return false;
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM sla_calendars WHERE id = ?");
        $stmt->execute([$calendarId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        return analystCanAccessTenant($conn, $analystId, $tid);
    } catch (Exception $e) {
        return tenancyDegradeAllowed($e);
    }
}

// hours/holidays/sent: gate pelo PAI (calendar_id / ticket_id), não pelo tenant
// desnormalizado da própria linha — a invariante filho==pai é checada pelo
// verify-tenant-consistency.php, e o gate segue a fonte (igual task→comments).
function slaCalendarTenantFilter(PDO $conn, int $analystId, string $alias = 'c'): array {
    if (!isMultiTenant($conn)) return ['', []];
    $qualified = $alias === '' ? 'tenant_id' : "$alias.tenant_id";
    $active  = getActiveTenantId($conn, $analystId);
    $default = getDefaultTenantId($conn);
    if ($active === $default) {
        return [" AND ($qualified = ? OR $qualified IS NULL)", [$active]];
    }
    return [" AND $qualified = ?", [$active]];
}
```

Pickers (prioridade→calendário, widget→calendário): resolver id pedido via
`requestedTenantId()`-style — id fora do alcance do analista é IGNORADO
(fallback para o ativo), nunca honrado; escrita usa `analystCanAssignTenant()`.

## Checklist de implementação (Agente 4)

- [ ] Guards by-id em todo endpoint endereçado por department/calendar/rule/hour/holiday id.
- [ ] Filtros em toda listagem; cron avalia por tenant do ticket.
- [ ] Escrita global de rule exige `analystHasAllTenantAccess()`.
- [ ] Resolução nome→id de departamento escopada por tenant; empate → deny.
- [ ] Nenhum `catch` genérico retornando `true` — só `tenancyDegradeAllowed()`.
