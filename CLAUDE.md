# CLAUDE.md — Finanças de Casa

Intranet para controle financeiro doméstico e de investimentos de um lar (hoje 2 usuários: Eduardo e esposa).
O plano completo, com as entregas e critérios de aceite, está em `PLAN.md`. **Leia o PLAN.md antes de qualquer tarefa.**

## Forma de trabalho

- O desenvolvimento é dividido em **entregas pequenas** (E1, E2, …). Trabalhe **uma entrega por vez**, na ordem do PLAN.md.
- Antes de começar uma entrega, apresente um plano curto (arquivos, migrations, telas, testes) e espere aprovação.
- Uma entrega só termina quando:
  1. todos os critérios de aceite dela no PLAN.md estão atendidos;
  2. `composer test`, `vendor/bin/pint --test` e `vendor/bin/phpstan analyse` passam;
  3. o PLAN.md foi atualizado (checkboxes marcados, desvios anotados em "Notas da entrega").
- **Não comece a próxima entrega sem aprovação explícita.** Não implemente nada de entregas futuras "para adiantar".
- Um commit por passo lógico, mensagens em português no formato `E<n>: <o que mudou>`.
- Se uma decisão do PLAN.md parecer errada ao implementar, pare e pergunte em vez de mudar por conta própria.

## Stack

- PHP, Laravel e Filament nas **versões estáveis mais recentes** (confira com `composer` ao criar o projeto; registre as versões no PLAN.md).
- PostgreSQL. Ambiente via Docker (Laravel Sail ou `docker compose` próprio).
- Testes com Pest. Estilo com Pint. Análise estática com Larastan (nível 6 ou mais).
- Filas e agendamentos: `database` queue + scheduler do Laravel rodando em container próprio.
- Anexos: disco `google` do Filesystem com adapter Flysystem para Google Drive (entrega 8).

## Convenções de domínio (obrigatórias)

- **Dinheiro:** sempre inteiro em centavos (`bigInteger`), nunca float. Use um value object `Money` (ou `brick/money`) e cast Eloquent próprio. Moeda em coluna `currency` (ISO 4217) quando puder não ser BRL.
- **Quantidades de ativos:** `decimal(20, 8)`. Preços unitários: `decimal(20, 8)`.
- **Multi-lar:** toda tabela de domínio tem `household_id`. Um global scope `BelongsToHousehold` filtra pelo lar do usuário logado e preenche o campo ao criar. Nunca consulte dados de domínio sem esse escopo.
- **Visibilidade:** conta `private` só é vista pelo dono (`owner_id`); conta `shared` por todos do lar. Lançamentos, faturas e anexos herdam a visibilidade da conta. Implemente com Policies + escopo, e teste os dois casos em toda entrega que criar telas.
- **Sinal dos valores:** despesas negativas, receitas positivas. Transferências são um par de lançamentos ligados por `transfer_id` e ficam fora de relatórios de receita/despesa.
- **Datas:** `date` = data de caixa; `competence_date` = mês de competência (usado em orçamento e fatura). Fuso `America/Sao_Paulo`.
- **Enums:** PHP backed enums em `app/Enums`, com `label()` em português.
- **Lógica de negócio** fica em classes de ação/serviço em `app/Domain/<Contexto>` (ex.: `Domain/CreditCard/AssignTransactionToInvoice`), nunca em Resources do Filament ou em Models. Os Resources só chamam essas classes.
- **Integrações externas** (cotações, câmbio, Google Drive) ficam atrás de interfaces em `app/Contracts` com implementação trocável e fake para testes. Testes nunca chamam APIs reais (`Http::fake()`).

## Idioma

- Código, nomes de tabelas, colunas e classes: **inglês**.
- Interface, mensagens de validação, enums `label()` e textos de notificação: **português do Brasil**.
- Formatação: `R$ 1.234,56`, datas `dd/mm/aaaa`.

## Testes

- Toda regra de negócio tem teste unitário/feature (Pest). Priorize: cálculo de saldo, fatura de cartão, parcelas, preço médio, conversão de moeda, saldo devedor, deduplicação de importação, visibilidade entre usuários.
- Use factories para todos os models. Seeder `DemoSeeder` com um lar, 2 usuários e dados de exemplo, atualizado a cada entrega.

## Segurança

- Sem registro público: usuários são criados por comando artisan ou por um admin do lar.
- 2FA obrigatório para todos os usuários.
- Segredos (tokens de API, credenciais do Google) só no `.env`, nunca versionados. Mantenha `.env.example` atualizado.
