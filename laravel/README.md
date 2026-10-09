# Laravel Migration Scaffold (Phase 3)

Este diretório contém a base Laravel para migração gradual do FreeITSM sem remover o sistema legado.

## O que já existe

- Estrutura inicial (`bootstrap`, `config`, `routes`, `app/Providers`, `public`)
- Bridge de compatibilidade para módulos legados em `app/Support/LegacyBridge.php`
- Bridge de sessão legada em `app/Support/LegacySessionBridge.php`
- Middleware que anexa contexto do analista legado: `app/Http/Middleware/AttachLegacyAnalystContext.php`
- Rotas internas da fase 2:
  - `/laravel/legacy/{module}/{path?}` (redirecionamento para módulos legados)
  - `/laravel/legacy/context` (diagnóstico de autenticação por sessão legada)
  - `/laravel/legacy/modules` (módulos canônicos e aliases iniciais)
- Catálogo modular da fase 3:
  - `/laravel/modules` (lista de módulos mapeados no catálogo Laravel)
  - `/laravel/modules/{module}` (detalhe de módulo com validação de acesso por `allowed_modules`)
  - Middleware de acesso: `app/Http/Middleware/EnsureLegacyModuleAccess.php`
  - Catálogo: `app/Support/ModuleCatalog.php` + `config/modules.php`

## Como instalar dependências

```bash
cd /home/runner/work/Teste/Teste/laravel
composer install
cp .env.example .env
php artisan key:generate
```

## Execução

Com Apache, acesse `/laravel` no mesmo host da aplicação.
As rotas legadas continuam em funcionamento no root atual (`/tickets`, `/assets`, etc.).

## Sessão compartilhada com legado

Para o bridge de autenticação funcionar no mesmo host:

- `LEGACY_SESSION_COOKIE` deve corresponder ao cookie do legado (padrão `PHPSESSID`).
- `LEGACY_SESSION_PATH` pode ser definido quando Laravel e legado usam `session.save_path` diferentes.
- `LEGACY_SESSION_FILE_PREFIX` normalmente permanece `sess_`.
