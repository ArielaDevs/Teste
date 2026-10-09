# Laravel Migration Scaffold (Phase 1)

Este diretório contém a base Laravel para migração gradual do FreeITSM sem remover o sistema legado.

## O que já existe

- Estrutura inicial (`bootstrap`, `config`, `routes`, `app/Providers`, `public`)
- Bridge de compatibilidade para módulos legados em `app/Support/LegacyBridge.php`
- Rota de redirecionamento legado: `/laravel/legacy/{module}/{path?}`

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
