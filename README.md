# Finanças de Casa

Intranet para controle financeiro doméstico e de investimentos de um lar.
Plano e entregas: [`PLAN.md`](PLAN.md). Convenções: [`CLAUDE.md`](CLAUDE.md).

Stack: PHP 8.5 · Laravel 13 · Filament 5 · PostgreSQL 18, tudo em Docker.

## Subir o ambiente

Pré-requisito: Docker com Compose. Não é preciso PHP nem Composer na máquina.

```bash
cp .env.example .env              # ajuste senhas; HOST_UID/HOST_GID = saída de `id -u` / `id -g`
docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Acesse `http://localhost:8000` (porta em `APP_PORT`). Na rede de casa, use o IP da máquina.

Serviços do `compose.yaml`:

| Serviço     | Função                                                   |
| ----------- | -------------------------------------------------------- |
| `app`       | PHP-FPM com a aplicação                                   |
| `web`       | nginx, publica a porta `APP_PORT`                         |
| `db`        | PostgreSQL 18 (volume `db-data`; cria também o banco `testing`) |
| `queue`     | `php artisan queue:work` (fila `database`)                |
| `scheduler` | `php artisan schedule:work`                              |
| `backup`    | `pg_dump` diário (ver [Backup](#backup))                  |

## Criar o lar e os usuários

Não há registro público. Crie o lar (com o primeiro usuário, administrador) e depois os demais membros:

```bash
docker compose exec app php artisan app:create-household
docker compose exec app php artisan app:add-member
```

No primeiro login cada usuário é obrigado a configurar o 2FA com um app autenticador
(Google Authenticator, 1Password, etc.) e recebe códigos de recuperação.

### Dados de demonstração

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Cria o lar "Casa Demo" com `eduardo@demo.local` e `maria@demo.local` (senha `password`),
contas pessoais e compartilhadas e 3 meses de lançamentos. Não roda em produção.

## Google Drive (comprovantes e cópia do backup)

Os comprovantes dos lançamentos ficam no Google Drive do lar. O app usa o escopo `drive.file`:
só enxerga os arquivos e pastas que ele mesmo cria (nada do resto do seu Drive).

1. No [Google Cloud Console](https://console.cloud.google.com/), crie um projeto e ative a **Google Drive API**
   (APIs e serviços → Biblioteca).
2. Em **Tela de permissão OAuth**, escolha "Externo", preencha nome e e-mail e adicione os escopos
   `.../auth/drive.file`, `openid` e `email`. Depois **publique o app ("Em produção")**: em "Teste" o Google
   expira a conexão a cada 7 dias. Com esses escopos não é preciso verificação.
3. Em **Credenciais → Criar credenciais → ID do cliente OAuth → Aplicativo da Web**, adicione o URI de
   redirecionamento `http://localhost:8000/google/callback`.
4. Coloque o ID e a chave no `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`) e rode
   `docker compose exec app php artisan config:clear`.
5. **No próprio computador** (endereço `localhost`; o Google não aceita o IP da rede de casa como retorno),
   entre como administrador do lar, abra **Configurações → Google Drive** e clique em **Conectar Google Drive**.
   Depois de conectado, os comprovantes funcionam de qualquer aparelho da rede.

Os arquivos ficam em `Finanças de Casa/ano/mês` (a pasta raiz pode ser renomeada na mesma tela) e são abertos
sempre pelo app, que confere quem pode ver cada um. Todo dia às 04:00 o backup mais recente de `./backups`
também é copiado para `Finanças de Casa/Backups` (mantidos 30 dias). Com mais de um lar, defina
`GOOGLE_BACKUP_HOUSEHOLD_ID`, já que o dump contém o banco inteiro.

Para desenvolver sem conta Google, use `ATTACHMENTS_DISK=local` no `.env`.

## Investimentos (cotações e CDI)

- **Cotações da B3:** crie um token gratuito em [brapi.dev](https://brapi.dev) e coloque em `BRAPI_TOKEN` no `.env`.
  As cotações são buscadas nos dias úteis às 19:00 (ou pelo botão "Atualizar cotações" na Carteira, ou
  `docker compose exec app php artisan app:fetch-quotes`). Sem cotação, o app usa a última disponível e avisa.
- **Ativos do exterior:** crie uma chave gratuita em [finnhub.io](https://finnhub.io) e coloque em
  `FINNHUB_TOKEN` no `.env` (ações e ETFs dos EUA). A conta da corretora precisa estar na moeda do ativo (ex.: USD).
- **Câmbio (PTAX do Banco Central, sem token):** atualizado nos dias úteis às 13:30 para as moedas usadas nas
  contas. Na primeira vez, carregue o histórico desde a primeira compra no exterior:

  ```bash
  docker compose exec app php artisan app:fetch-exchange-rates --from=2020-01-01
  ```

- **CDI (Banco Central, sem token):** atualizado todo dia às 09:00 com os últimos 30 dias. Na primeira vez,
  carregue o histórico desde o início dos seus investimentos:

  ```bash
  docker compose exec app php artisan app:fetch-cdi --from=2020-01-01
  ```

## Testes e qualidade

```bash
docker compose exec app composer test               # Pest, no banco `testing` do Postgres
docker compose exec app vendor/bin/pint --test      # estilo
docker compose exec app vendor/bin/phpstan analyse  # Larastan nível 6
```

## Backup

O container `backup` faz um `pg_dump` em formato custom (já compactado) todos os dias às
`BACKUP_AT` (padrão 03:00) e grava em `BACKUP_PATH` (padrão `./backups`, fora do git).
Arquivos com mais de `BACKUP_RETENTION_DAYS` (padrão 30) dias são apagados.

```bash
docker compose logs backup            # histórico dos backups
docker compose run --rm backup now    # backup imediato
```

> A pasta `./backups` fica na mesma máquina do banco. Copie-a periodicamente para outro lugar
> (HD externo, nuvem) até a entrega que envia o backup ao Google Drive.

### Restaurar um backup

1. Escolha o arquivo em `./backups` (ex.: `household_finances_2026-09-28_0300.dump`).
2. Pare quem escreve no banco:

   ```bash
   docker compose stop web app queue scheduler
   ```

3. Recrie o banco e restaure (troque usuário/banco se mudou no `.env`):

   ```bash
   docker compose exec db dropdb -U household household_finances
   docker compose exec db createdb -U household household_finances
   docker compose exec -T db pg_restore -U household -d household_finances --no-owner \
       < backups/household_finances_2026-09-28_0300.dump
   ```

4. Suba de novo e confira:

   ```bash
   docker compose start app web queue scheduler
   docker compose exec db psql -U household -d household_finances \
       -c "select count(*) from transactions;"
   ```

Para só conferir um backup sem mexer no banco principal, restaure num banco à parte
(`createdb restore_check`, `pg_restore -d restore_check ...`) e apague-o depois.

**Teste de restauração realizado em 28/09/2026:** backup do banco com os dados de demonstração
restaurado no banco `restore_check`. Contagens de lares (1), usuários (2), contas (5),
categorias (42) e lançamentos (39), e a soma dos valores dos lançamentos, idênticas às do original.
