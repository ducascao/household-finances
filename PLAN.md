# PLAN.md — Finanças de Casa

Plano de implementação em entregas pequenas. Cada entrega deixa o sistema **utilizável** e agrega valor real sozinha.
Regras de trabalho e convenções: ver `CLAUDE.md`.

Legenda: `[ ]` pendente · `[x]` feito. Ao terminar uma entrega, marque os itens e preencha "Notas da entrega".

---

## Decisões fechadas

| Tema | Decisão |
| --- | --- |
| Stack | Laravel + Filament + PostgreSQL (versões estáveis mais recentes) |
| Execução | Docker no computador do Eduardo; acesso na rede de casa; pronto para migrar para servidor |
| Usuários | Lar (household) com membros; hoje 2, extensível |
| Visibilidade | Contas pessoais (privadas ao dono) e compartilhadas |
| Divisão de despesas | Sem rateio; lançamento registra só quem pagou (`paid_by`) |
| Entrada de dados | Manual + importação OFX/CSV |
| Cartão | Por fatura (fechamento, vencimento, parcelas) |
| Orçamento | Mensal por categoria |
| Dívidas | Saldo devedor, parcelas (Price/SAC), juros |
| Investimentos | B3 (ações, FIIs, ETFs, BDRs), renda fixa, exterior, previdência |
| Renda fixa e previdência | Saldo manual por data |
| Cotações | Job diário automático + ajuste manual |
| Moeda estrangeira | Valor na moeda original + conversão para BRL pela cotação do dia |
| Anexos | Google Drive |
| Fora do escopo | IR, Open Finance, app mobile |

Versões instaladas (E1, 28/09/2026): PHP `8.5.11` · Laravel `13.33.0` (skeleton 13.10.1) · Filament `5.9.0` · PostgreSQL `18.6` · Pest `5.2.1` · Larastan `3.12.2` · brick/money `0.15.1`

---

## Bloco A — Dia a dia

### E1 — Livro-caixa mínimo
**Valor:** registrar receitas e despesas e ver o saldo de cada conta.

Escopo
- [x] Projeto Laravel + Filament + PostgreSQL em Docker (app, db, queue, scheduler)
- [x] Pest, Pint e Larastan configurados; script `composer test`
- [x] Tabelas `households`, `household_user` (papel: admin, member); global scope `BelongsToHousehold`
- [x] Login no Filament, sem registro público; 2FA obrigatório
- [x] Comando `app:create-household` e `app:add-member` para criar o lar e os usuários
- [x] `accounts`: nome, tipo (checking, savings, cash, credit_card, brokerage), `owner_id`, `visibility` (private, shared), `currency`, `initial_balance`, `archived_at`
- [x] `categories`: nome, tipo (income, expense), `parent_id` (máx. 2 níveis), cor; categorias padrão criadas junto com o lar
- [x] `transactions`: conta, categoria, `amount` (centavos, com sinal), `date`, `competence_date`, `description`, `paid_by`, `notes`, tags
- [x] Resource de lançamentos com filtros (conta, categoria, período, pago por) e lançamento rápido
- [x] Saldo atual por conta (inicial + lançamentos) no painel
- [x] Backup diário do Postgres (`pg_dump` compactado, retenção de 30 dias em pasta local)
- [x] `DemoSeeder` com um lar, 2 usuários e dados de exemplo

Critérios de aceite
- [x] Usuário A não vê contas privadas nem lançamentos das contas privadas do usuário B (teste)
- [x] Ambos veem e editam contas compartilhadas (teste)
- [x] Saldo da conta confere com a soma dos lançamentos (teste)
- [x] Restauração do backup testada manualmente e documentada no README

Notas da entrega:
- **Docker:** `compose.yaml` próprio (sem Sail): `app` (PHP-FPM 8.5), `web` (nginx), `db` (Postgres 18, cria também o banco `testing`), `queue`, `scheduler` e `backup`. Não há PHP/Composer no host; tudo roda via `docker compose exec app ...`.
- **Painel** na raiz (`/`), id `app`. 2FA pelo MFA nativo do Filament (TOTP por app autenticador + códigos de recuperação), obrigatório: sem 2FA o usuário é levado à tela de configuração. Sem registro e sem recuperação de senha pública. Só entra no painel quem tem um lar.
- **Escopos:** `BelongsToHousehold` filtra pelo `current_household_id` do usuário logado; sem usuário logado (console/jobs) não filtra, e as ações informam o `household_id` explicitamente. A visibilidade das contas também virou **global scope** (`VisibleAccountScope`, com usuário logado), em vez de só escopo local; lançamentos herdam via `whereHas('account')`. As Policies repetem a regra.
- **Desvio:** `transactions` tem coluna `currency` (cópia da moeda da conta, gravada pela ação), para o cast `Money` não depender da relação. A moeda da conta não pode mudar depois que ela tem lançamentos.
- **Sinal:** o usuário digita o valor sem sinal; `CreateTransaction`/`UpdateTransaction` gravam negativo se a categoria é de despesa e positivo se é de receita. Por isso `category_id` é obrigatório (NOT NULL); transferências (E2) vão precisar rever isso.
- `competence_date` é gravada sempre no dia 1 do mês; em branco, vale o mês da data.
- **Tags** em coluna `jsonb` (índice GIN) no próprio lançamento, sem tabela de tags.
- **Categorias:** padrão criadas pelo `CreateHousehold` (3 de receita; 10 de despesa com subcategorias). Não é possível excluir categoria com filhas ou lançamentos, nem mudar o tipo nesses casos. Conta com lançamentos não pode ser excluída (arquivar).
- **Saldo:** `AccountBalance` (inicial + soma dos lançamentos numa subquery). Aparece na lista de contas e no widget "Saldos das contas" do painel, com total por moeda (sem conversão).
- **Lançamento rápido:** ação no topo da lista (atalho `Ctrl/Cmd+Shift+L`) com conta, categoria, valor, data e descrição; tem "criar e criar outro".
- **Backup:** `pg_dump --format=custom --compress=9` (já compactado, sem gzip adicional), diário às 03:00, retenção de 30 dias, em `./backups`. Restauração testada em 28/09/2026 num banco à parte; o procedimento está no README.
- `Model::shouldBeStrict()` ligado fora de produção.
- Traduções pt_BR via `laravel-lang/common` (dev) publicadas em `lang/`.
- O skeleton do Laravel 13 vem com `CLAUDE.md`/`AGENTS.md` do Laravel Boost; foram descartados (o nosso `CLAUDE.md` foi mantido).

### E2 — Contas a pagar
**Valor:** saber o que vence nos próximos dias.

Escopo
- [x] `status` do lançamento (scheduled, paid) e `due_date`; saldo considera só os pagos, com saldo projetado à parte
- [x] Ação "marcar como pago" (individual e em massa), podendo ajustar data e valor
- [x] Transferências entre contas (`transfer_id` ligando o par); excluídas de relatórios de receita/despesa
- [x] Widget no painel: atrasados, vencendo em 7 dias, total previsto do mês

Critérios de aceite
- [x] Transferência gera exatamente 2 lançamentos de sinais opostos e editar/excluir um afeta o par (teste)
- [x] Lançamento vencido e não pago aparece como atrasado (teste)

Notas da entrega:
- **Datas:** num lançamento previsto, `date` = `due_date`; ao pagar, `date` vira a data real do pagamento e `due_date` fica como referência. A competência de um previsto sem competência informada é o mês do vencimento.
- **Saldo projetado** = saldo atual + previstos com vencimento até o **fim do mês corrente**, incluindo atrasados. Aparece no widget de saldos e na lista de contas.
- **Constraints no Postgres:** categoria existe se e somente se não é transferência; previsto sempre tem vencimento. Lançamentos existentes viraram `paid`.
- **Marcar como pago:** individual com data e valor (o sinal é mantido); em massa só com a data. Numa transferência, as duas pernas são pagas juntas.
- **Transferências:** `CreateTransfer`/`UpdateTransfer`/`DeleteTransfer` em `app/Domain/Transfers`. Sem categoria e só entre contas da mesma moeda (câmbio fica para a E12). Editar ou excluir qualquer perna, inclusive na exclusão em massa, afeta o par. O formulário de lançamento comum não edita pernas.
- **Visibilidade de transferência entre conta privada e compartilhada:** quem não é dono da privada vê só a perna compartilhada, descrita como "Transferência de/para conta pessoal de <dono>", e não pode editar nem excluir. Só quem vê as duas contas altera o par (Policy + ações).
- **Relatórios:** escopo `Transaction::incomeAndExpense()` exclui transferências; os widgets da E2 já o usam, e a E6 deve usá-lo.
- **Painel:** widget "Contas a pagar e a receber" (atrasados, vencendo em 7 dias, previsto do mês a pagar/a receber) e lista "Atrasados e próximos 7 dias" com o botão "Pagar". Transferências previstas ficam fora dos números.
- A descrição das pernas de transferência na lista faz 2 consultas por linha de transferência. É aceitável no volume de um lar; se pesar, trocar por eager loading.

### E3 — Contas fixas
**Valor:** parar de relançar aluguel, luz e salário todo mês.

Escopo
- [x] `recurrences`: modelo do lançamento, frequência (mensal, semanal, anual, a cada N meses), dia, próxima data, data fim, valor fixo ou estimado
- [x] Job diário gera os lançamentos previstos até 60 dias à frente, sem duplicar
- [x] Editar recorrência pergunta se altera só os futuros ainda não pagos
- [x] Lançamento gerado aponta para a recorrência de origem

Critérios de aceite
- [x] Rodar o job duas vezes não duplica lançamentos (teste)
- [x] Dia 31 em meses curtos cai no último dia do mês (teste)

Notas da entrega:
- **Tabela `recurrences`:** conta, categoria, valor com sinal (como nos lançamentos) e moeda, `amount_is_estimate`, descrição, pago por, tags, observações, `frequency` (weekly, monthly, every_n_months, yearly), `interval_months`, `day_of_month`, `start_date`, `next_date` (próxima ocorrência ainda não gerada) e `end_date`. Herda a visibilidade da conta (escopo global + Policy).
- **Datas:** mensal, anual e a cada N meses usam o dia configurado, guardado à parte; no mês curto cai no último dia e depois volta ao dia configurado. As ocorrências ficam alinhadas ao mês da data de início; anual em 29/02 vira 28/02 fora de ano bissexto; semanal é a cada 7 dias a partir do início.
- **Não duplicar:** o ponteiro `next_date` avança a cada geração, então um lançamento gerado e excluído à mão não volta. O índice único `(recurrence_id, occurrence_date)` em `transactions` é a garantia no banco. `occurrence_date` não muda quando o vencimento é editado ou o lançamento é pago.
- **Geração:** job `GenerateRecurringTransactions` diário às 01:00 no container `scheduler`, mais o comando `app:generate-recurrences`. Gera previstos até hoje + 60 dias; ao criar uma recorrência, gera na hora. Início no passado gera previstos já atrasados. Conta arquivada não gera.
- **Edição:** o formulário exige escolher o alcance: "só as próximas" ou "também os previstos já gerados, não pagos, de hoje em diante" (apaga e gera de novo). Pagos e atrasados nunca mudam. A próxima ocorrência segue no período seguinte ao último gerado (ex.: dia 10 → 15 não repete o mês).
- **Exclusão:** pergunta se exclui também os previstos futuros não pagos; os demais lançamentos ficam, sem vínculo (`nullOnDelete`).
- **Lançamentos:** ícone de "gerado por conta fixa", filtro por conta fixa e ação que abre a recorrência de origem.
- **Valor estimado:** só marca o valor como estimativa ("~" na lista); o valor real é informado ao pagar.
- **Fora do escopo:** recorrência de transferência (ex.: aporte mensal na conjunta) — pode entrar numa entrega futura.

### E4 — Cartão por fatura
**Valor:** ver a fatura aberta e as próximas, com parcelas no mês certo.

Escopo
- [x] `credit_cards` (1:1 com conta do tipo credit_card): dia de fechamento, dia de vencimento, limite
- [x] `invoices`: cartão, mês de referência, data de fechamento, vencimento, status (open, closed, paid); criadas sob demanda
- [x] Regra: compra em data ≥ fechamento cai na fatura seguinte; `competence_date` = mês da fatura
- [x] `installment_groups`: compra parcelada gera N lançamentos, um por fatura seguinte, com "3/10" na descrição; última parcela absorve o arredondamento
- [x] Estornos (valor positivo) na fatura
- [x] Pagamento de fatura = transferência da conta corrente para o cartão; marca a fatura como paga
- [x] Tela da fatura: itens, total, limite disponível, próximas faturas com parcelas já comprometidas
- [x] Vencimento da fatura aparece no widget de contas a pagar

Critérios de aceite
- [x] Compra no dia do fechamento cai na fatura correta (teste com várias combinações de dias)
- [x] 1000,00 em 3x gera 333,33 + 333,33 + 333,34 (teste)
- [x] Soma das parcelas futuras reduz o limite disponível (teste)

Notas da entrega:
- **Desvio (aprovado):** `invoices` não tem coluna `status`. "Paga" é gravado (`paid_at`, `payment_transfer_id`); "aberta" e "fechada" vêm da data (fechada quando hoje ≥ fechamento). O enum `InvoiceStatus` tem os três valores, e `Invoice::status()` os calcula.
- **Mês da fatura** = mês do vencimento, e é a `competence_date` das compras. Vencimento no mesmo mês do fechamento quando o dia de vencimento é maior que o de fechamento; senão, no mês seguinte (inclusive dias iguais). Dias 29–31 caem no último dia do mês.
- **Regra de fechamento** em `Domain/CreditCard/InvoiceSchedule` + `AssignTransactionToInvoice`, chamada por `CreateTransaction`, `UpdateTransaction` e pela geração de contas fixas. Compra que cairia em fatura já paga vai para a seguinte. Lançamento em fatura paga não pode ter conta, valor ou data alterados, nem ser excluído.
- **Cartão:** `credit_cards` guarda `currency` (cópia da conta) para o cast do limite. É configurado na própria tela de contas quando o tipo é cartão. Mudar os dias vale para as faturas ainda não criadas.
- **Compras no cartão são "pagas":** a dívida já existe, inclusive a das parcelas futuras. O saldo da conta do cartão é a dívida total. Limite disponível = limite − compras e parcelas em faturas não pagas.
- **Parcelas:** `installment_groups` + `installment_group_id`/`installment_number` nos lançamentos. A data de cada parcela é a da compra + (k−1) meses; a fatura é a da compra + (k−1) meses. Editar vale para o grupo (descrição e categoria); excluir uma parcela exclui a compra, bloqueado se alguma parcela está em fatura paga. Valor/nº de parcelas: excluir e lançar de novo.
- **Estorno:** opção no lançamento de despesa que grava valor positivo (vale para qualquer conta, não só cartão).
- **Pagamento:** `PayInvoice` cria uma transferência da conta escolhida para o cartão (a perna do cartão não entra em fatura) e marca a fatura como paga; excluir essa transferência reabre a fatura. Pagamento parcial/rotativo fora do escopo: pagar sempre marca como paga.
- **Tela "Faturas":** lista (cartão, mês, fechamento, vencimento, total, situação) e tela da fatura com itens, total, limite total/disponível, próximas faturas com parcelas comprometidas e o botão "Pagar fatura".
- **Painel:** faturas não pagas com valor entram em atrasados, vencendo em 7 dias e previsto do mês (a pagar), mais a lista "Faturas de cartão…" com "Pagar". Itens do cartão (inclusive contas fixas no cartão) entram só pela fatura, para não contar em dobro.

### E5 — Importação de extratos
**Valor:** trazer o mês do banco em minutos, sem digitar.

Escopo
- [x] Upload de OFX e CSV associado a uma conta (ou cartão)
- [x] Mapeamento de colunas do CSV salvo por banco/conta (data, descrição, valor, formato de data e decimal)
- [x] `import_batches` e hash por linha (conta + data + valor + descrição normalizada) para deduplicação
- [x] `import_rules`: "descrição contém X" → categoria (e opcionalmente descrição amigável)
- [x] Tela de revisão antes de gravar: nova, duplicada, possível correspondência com lançamento previsto (mesmo valor ± 3 dias)
- [x] Ao confirmar correspondência, o previsto vira pago em vez de criar outro lançamento
- [x] Criar regra a partir de uma linha na revisão

Critérios de aceite
- [x] Importar o mesmo arquivo duas vezes não duplica nada (teste)
- [x] Previsto correspondente é baixado, não duplicado (teste)
- [x] Testes com arquivos OFX/CSV de exemplo em `tests/Fixtures`

Notas da entrega:
- **Bancos do lar:** Bradesco, PicPay, Nubank e Mercado Pago. Perfis de CSV prontos em `Domain/Import/BankPresets`: Nubank conta, Nubank cartão (inverte sinal), Bradesco conta (crédito/débito separados), Mercado Pago e PicPay. **Bradesco, Mercado Pago e PicPay estão "a conferir":** foram montados pelo formato conhecido e precisam ser validados com um arquivo real (o perfil pode ser ajustado na tela). Quando houver OFX, é o formato recomendado.
- **Leitores:** `OfxParser` próprio (SGML sem fechamento e XML, Windows-1252 → UTF-8, `MEMO` ou `NAME`) e `CsvParser` guiado pelo perfil (colunas a partir de 1, valor ou débito/crédito, formatos de data com ou sem hora, decimal/milhar, inverter sinal). Linhas sem data ou valor válidos (saldo anterior, totais) são ignoradas.
- **Hash** = conta + data + valor + descrição normalizada + nº da ocorrência entre linhas idênticas do mesmo arquivo (dois cafés iguais no mesmo dia viram 2 lançamentos). Gravado em `transactions.import_hash`, com índice único `(account_id, import_hash)`. A duplicidade é checada na leitura e de novo na confirmação (dois lotes do mesmo arquivo).
- **Revisão:** cada linha é "nova", "duplicada" (só pode ser ignorada) ou "corresponde a previsto" (mesmo valor, vencimento a ±3 dias, o mais próximo; padrão "baixar previsto"). Ação, categoria e descrição são editáveis na linha. Sem regra, a categoria é "Outras despesas"/"Outras receitas" pelo sinal.
- **Regras:** "descrição contém" (sem diferenciar maiúsculas e acentos) → categoria + descrição amigável, ou **ignorar** (ex.: "Pagamento recebido" no cartão). A primeira pela ordem vence, e a ordem é arrastável. "Criar regra" na revisão já reaplica às linhas novas do lote sem regra.
- **Confirmação:** tudo numa transação. Cria por `CreateTransaction` (no cartão cai na fatura certa; valor positivo com categoria de despesa vira estorno) e baixa previstos por `MarkAsPaid` com data e valor do extrato. Valor negativo com categoria de receita bloqueia a confirmação, indicando a linha.
- **Arquivo enviado é apagado depois de lido;** ficam só as linhas. Lote descartado apaga as linhas.
- Lotes, linhas e perfis herdam a visibilidade da conta; regras são do lar.
- **Teste de tela:** com `Carbon::setTestNow` o upload falso do Livewire quebra (JSON inválido), então os testes de tela de importação rodam sem relógio congelado. As regras de data estão cobertas nos testes de domínio.

---

## Bloco B — Visão e controle

### E6 — Painel do mês
**Valor:** entender para onde foi o dinheiro.

Escopo
- [x] Seletor de mês; entradas × saídas por competência (sem transferências)
- [x] Gastos por categoria (pai e filha)
- [x] Evolução dos últimos 12 meses
- [x] Projeção de saldo das contas até o fim do mês com base nos previstos
- [x] Todos os números respeitam a visibilidade do usuário

Critérios de aceite
- [x] Totais do painel batem com a soma dos lançamentos filtrados (teste)

Notas da entrega:
- **Página própria "Resumo do mês"** (`/resumo-do-mes`), um segundo dashboard do Filament com filtro de mês (lista + botões anterior/atual/próximo). O "Painel de Controle" continua sendo o do dia a dia. Os widgets do resumo ficam em `app/Filament/Monthly`, fora da descoberta automática, para não aparecerem no painel principal.
- **Cálculos** em `Domain/Reports/MonthlySummary`, por competência e sem transferências (`incomeAndExpense()`). Receita e despesa vêm do **tipo da categoria**, não do sinal, então o estorno abate a despesa. Totais separados em realizado e previsto (os previstos entram no total).
- **Moeda:** só lançamentos em BRL entram nos totais; se houver outra moeda no mês, uma nota avisa que fica de fora até a E12.
- **Desvio de forma:** gastos por categoria em **barras horizontais de uma cor**, do maior para o menor (acima de 8 categorias o resto vira "Outras"), em vez de rosca. É a forma certa para comparar valores próximos (guia de visualização de dados). Mais uma tabela principal → subcategorias com %; clicar abre os lançamentos filtrados por categoria + competência (novo filtro "Competência" nos lançamentos).
- **Evolução de 12 meses:** barras entradas (azul) × saídas (laranja) e o resultado como linha no mesmo eixo (mesma unidade, R$). Paleta validada para daltonismo nos modos claro e escuro. Limitação: as cores do Chart.js são fixas (as do modo claro também no modo escuro).
- **Saldo no fim do mês:** `AccountBalance::at()` = inicial + pagos até a data + (se a data não passou) previstos que vencem até ela, inclusive atrasados. Mês encerrado mostra o saldo real no último dia; parcelas futuras do cartão aparecem no mês delas.
- `DemoSeeder` passou a ter 6 meses de histórico para a evolução ficar legível.

### E7 — Orçamento
**Valor:** saber se está gastando além do combinado.

Escopo
- [x] `budgets`: categoria, mês, valor; orçamento na categoria pai soma as filhas
- [x] Copiar orçamento do mês anterior
- [x] Tela orçado × realizado com barra de progresso; destaque a partir de 80% e acima de 100%
- [x] Widget no painel com as categorias estouradas

Critérios de aceite
- [x] Realizado usa `competence_date` (compra no cartão conta no mês da fatura) (teste)

Notas da entrega:
- **Tabela `budgets`** (categoria de despesa, mês, valor em centavos, BRL), única por categoria e mês. `SaveBudget` cria, altera ou remove (vazio/zero remove). `CopyPreviousMonth` só preenche categorias sem valor no mês (não sobrescreve, pode repetir).
- **Cálculo** em `Domain/Budgets/BudgetReport`, na mesma base do Resumo do mês: por competência, sem transferências, líquido de estornos. A principal soma o gasto das subcategorias. O orçado da principal é o próprio ou, sem valor, a soma dos orçados das subcategorias, com aviso quando as subcategorias passam do orçado da principal.
- **Situação** pelo comprometido (realizado + previsto), para antecipar estouros: < 80% dentro, 80–100% atenção, > 100% estourado. A barra mostra a situação escrita (selo), não só a cor.
- **Orçamento é do lar; o realizado respeita a visibilidade** de quem vê (gasto em conta pessoal do outro não aparece).
- **Tela "Orçamento"** (`/orcamento`): mês anterior/próximo, "Copiar do mês anterior", "Definir orçamento", orçado editável na própria linha (formato 1.234,56). Clicar na linha abre os lançamentos da categoria no mês.
- **Painel de Controle:** widget "Orçamento estourado neste mês", que só aparece quando há estouro, com a quantidade de categorias em atenção.
- **Ponto a observar no uso:** conta fixa com orçado exatamente igual ao valor (ex.: aluguel) fica em "Atenção" (100%) quando lançada. Se incomodar, dá para tratar 100% exato como "dentro".

### E8 — Comprovantes
**Valor:** achar a nota ou o boleto de qualquer lançamento.

Escopo
- [x] Conexão OAuth com a conta Google do lar; pasta raiz configurável
- [x] Disco `google` via adapter Flysystem; arquivos organizados em `ano/mês`
- [x] `attachments`: lançamento, id do arquivo no Drive, nome, tipo, tamanho
- [x] Upload e visualização a partir do lançamento; indicador de anexo na listagem
- [x] Opcional: backup diário da E1 também enviado ao Drive

Critérios de aceite
- [x] Anexo de lançamento em conta privada só é acessível ao dono (teste)
- [x] Integração testada com fake do disco

Notas da entrega:
- **Pacotes:** `google/apiclient` 2.20 (só o serviço Drive, via `Google\Task\Composer::cleanup`) e `masbug/flysystem-google-drive-ext` 2.5. **Desvio:** o adapter exige Guzzle 6/7, então o Guzzle desceu de 8.2 para 7.15.5; o Laravel 13 aceita `^7.8.2 || ^8.0`.
- **Conexão:** tabela `google_connections`, uma por lar, com o refresh token criptografado. Tela "Configurações → Google Drive" só para admin: conectar, renomear a pasta raiz, desconectar (revoga o token). O callback em `/google/callback` confere o `state` da sessão (CSRF). Escopos `drive.file` + `openid email`: o app só vê o que criou. O fluxo OAuth fica atrás de `App\Contracts\GoogleOAuth`, com fake nos testes.
- **Armazenamento** atrás de `App\Contracts\AttachmentStorage` (`FilesystemAttachmentStorage`). O disco `google` é registrado com `Storage::extend` e montado por lar com o token da conexão. `ATTACHMENTS_DISK` troca por outro disco (ex.: `local` em desenvolvimento); nos testes, `Storage::fake('google')`. Cada anexo grava em qual disco está.
- **Anexos:** tabela `attachments` (lançamento, disco, caminho, id do arquivo no Drive, nome, tipo, tamanho, quem enviou). Ficam em `<pasta raiz>/<ano>/<mês>` pela data do lançamento, com nome `AAAA-MM-DD descrição - arquivo`, sem sobrescrever. Aceita PDF/JPG/PNG/WEBP/HEIC até 10 MB, com o tipo detectado pelo conteúdo; o envio é síncrono.
- **Acesso:** o arquivo é servido pelo app em `/anexos/{id}` (inline para PDF/imagem, `?download=1` para baixar), com a Policy herdando a visibilidade da conta; nunca por link público do Drive.
- **Limpeza:** excluir o anexo, o lançamento, a compra parcelada ou previstos de recorrência remove os arquivos por job (`DeleteStoredAttachment`, na fila). Para isso, as exclusões em massa de lançamentos passaram a ser feitas uma a uma.
- **Telas:** coluna com clipe e quantidade na lista de lançamentos; ação "Comprovantes" (lista com abrir/baixar/excluir + envio de vários arquivos) na linha e na edição do lançamento.
- **Backup:** `app:backup-to-drive` às 04:00 envia o dump mais recente para `<pasta raiz>/Backups`, sem reenviar o mesmo arquivo, e remove do Drive o que tem mais de 30 dias. Como o dump contém o banco inteiro, só envia para um lar (`GOOGLE_BACKUP_HOUSEHOLD_ID` ou o único conectado).
- `DemoSeeder` cria 2 recibos de aluguel no disco local (os dados de exemplo não têm Drive).

---

## Bloco C — Investimentos

### E9 — Carteira B3
**Valor:** ver posição e preço médio de ações, FIIs, ETFs e BDRs.

Escopo
- [x] `assets`: tipo, ticker, nome, moeda, conta da corretora
- [x] `asset_operations`: compra/venda, quantidade, preço, taxas, data; gera lançamento na conta da corretora
- [x] Serviço de posição: quantidade, preço médio (inclui taxas; venda não altera o PM), custo total
- [x] Desdobramentos e grupamentos como operação especial que ajusta quantidade e PM
- [x] Contrato `QuoteProvider` + implementação para B3 (avaliar brapi) + job diário de cotações em `asset_prices`
- [x] Ajuste manual de cotação
- [x] Tela da carteira: posição, valor de mercado, resultado não realizado, distribuição por tipo

Critérios de aceite
- [x] Preço médio correto em sequência de compras, vendas parciais e desdobramento (testes)
- [x] Falha da API não quebra o job; posição usa a última cotação disponível (teste)

Notas da entrega:
- **Tabelas:** `assets` (corretora, tipo, ticker, nome, moeda), `asset_operations` (compra, venda, desdobramento, grupamento; quantidade, preço e proporção em `decimal(20,8)`, taxas em centavos) e `asset_prices` (data, preço, fonte `api`/`manual`). Ativo, operação e cotação herdam a visibilidade da conta da corretora; o ativo só pode ficar em conta do tipo **corretora**.
- **Compra e venda geram lançamento na corretora** com `asset_operation_id` e **sem categoria**: compra = −(qtd × preço + taxas), venda = qtd × preço − taxas. Ficam fora de receita/despesa (`incomeAndExpense()` exclui) e, portanto, do Resumo do mês e do orçamento, mas entram no saldo da corretora. A constraint do Postgres passou a ser "tem categoria ⇔ não é transferência nem operação de ativo". Esse lançamento não é editado como lançamento comum; excluí-lo exclui a operação.
- **Preço médio** em `Domain/Investments/PositionCalculator` com `brick/math` (8 casas, sem float), em ordem de data: taxas entram no PM; a venda não muda o PM; zerar a posição zera o custo; desdobramento 1→N (qtd × N, PM ÷ N) e grupamento N→1 (qtd ÷ N, PM × N) mantêm o custo. `ManageOperations` recalcula a história inteira ao lançar, editar ou excluir e recusa qualquer mudança que deixe a quantidade negativa em algum ponto.
- **Cotações:** `App\Contracts\QuoteProvider` + `BrapiQuoteProvider` (uma requisição por ticker, timeout de 10 s com 2 tentativas, token em `BRAPI_TOKEN`). Job `FetchQuotes` em dias úteis às 19:00 e comando `app:fetch-quotes`. Erro da API é registrado no log sem interromper; a posição usa a última cotação gravada e, sem nenhuma, o preço médio (o app avisa quando a cotação falta ou tem mais de 7 dias). Cotação manual prevalece sobre a automática na mesma data.
- **Telas:** "Investimentos → Carteira" (custo, valor de mercado, resultado não realizado; distribuição por tipo em barras com %; posições com PM, cotação e data, resultado e % da carteira; botão "Atualizar cotações") e "Investimentos → Ativos" (cadastro; tela do ativo com a posição, as operações — lançar/editar/excluir — e as cotações com "Ajustar cotação").
- **Fornecedor de cotações definido: brapi** (precisa de token gratuito em brapi.dev; sem ele só alguns tickers de teste respondem).
- Totais da carteira em BRL; ativos em outra moeda entram na E12.
- `DemoSeeder`: corretora compartilhada "XP Investimentos" com aporte da conta conjunta, PETR4 (com venda parcial), BBAS3 (com desdobramento 1→2), HGLG11 e BOVA11, e cotações fictícias dos últimos 5 dias úteis.

### E10 — Proventos e rentabilidade
**Valor:** saber quanto a carteira rende de verdade.

Escopo
- [x] `asset_incomes`: dividendo, JCP, rendimento; gera receita na conta da corretora
- [x] Resultado realizado nas vendas
- [x] Rentabilidade por ativo e total (valorização + proventos) por período
- [x] Série diária do CDI (API SGS do Banco Central) para comparação

Critérios de aceite
- [x] Rentabilidade confere com cálculo manual em cenário de teste

Notas da entrega:
- **Proventos:** tabela `asset_incomes` (tipo dividendo/JCP/rendimento de FII, data de pagamento, bruto, IR retido). Cada provento gera **receita pelo líquido** na corretora (`asset_income_id`), que entra no Resumo do mês e no orçamento. "Rendimentos" ganhou as subcategorias Dividendos, JCP e Rendimentos de FII (padrão para lares novos + migration para os existentes); a categoria é escolhida automaticamente pelo tipo, podendo trocar. O lançamento só é editado pelo provento; excluí-lo exclui o provento.
- **Resultado realizado:** `PositionCalculator::history()` devolve, para cada venda, valor líquido − quantidade × PM na data. Aparece na coluna "Resultado realizado" das operações e nos totais (Carteira e Rentabilidade).
- **CDI:** tabela `interest_rates` (série, data, taxa diária em %). **Exceção consciente à regra do `household_id`:** é dado público de mercado, igual para todos os lares. Contrato `App\Contracts\InterestRateProvider` + `BcbSgsProvider` (SGS série 12, blocos de até 10 anos). Job diário às 09:00 (últimos 30 dias, sem duplicar), comando `app:fetch-cdi --from=` para o histórico; falha só é registrada no log. Acumulado = ∏(1 + taxa/100) − 1. A página avisa quando a série não cobre o início do período.
- **Rentabilidade (Dietz modificado)** em `Domain/Investments/ReturnCalculator`: valor inicial (posição no dia anterior × última cotação até lá, ou PM), valor final, compras e vendas do período ponderadas por (dias do período − dias até o fluxo) ÷ dias do período, proventos líquidos no resultado. Resultado = valorização + proventos; % = resultado ÷ (valor inicial + fluxos ponderados). Conferido com cálculo manual no teste (41,85% no cenário do critério de aceite).
- **Tela "Investimentos → Rentabilidade":** períodos (mês atual, 12 meses, ano, desde o início, datas à escolha), cards carteira %, CDI %, % do CDI e composição do resultado (valorização, proventos, realizado), e tabela por ativo. A Carteira ganhou o card "Proventos em 12 meses" (com o realizado em vendas); a tela do ativo ganhou a aba "Proventos".
- **Limitação conhecida:** o valor de períodos antigos usa a última cotação conhecida até a data (ou o PM), pois o app só guarda cotações desde que começou a buscá-las; dá para melhorar com "Ajustar cotação" em datas passadas.
- `DemoSeeder`: cotações de fim de mês dos últimos 6 meses, dividendo de PETR4, JCP de BBAS3, rendimentos mensais de HGLG11 e CDI fictício dos últimos 7 meses.

### E11 — Renda fixa e previdência
**Valor:** ter toda a carteira brasileira num lugar.

Escopo
- [ ] Ativos dos tipos fixed_income e pension sem ticker; campos informativos (emissor, indexador, vencimento)
- [ ] Aportes e resgates como operações por valor
- [ ] `manual_valuations`: saldo informado por data; lembrete no painel se o último saldo tiver mais de 30 dias
- [ ] Rendimento = último saldo − (aportes − resgates)

Critérios de aceite
- [ ] Posição total da carteira inclui renda fixa e previdência pelo último saldo (teste)

Notas da entrega:

### E12 — Exterior
**Valor:** incluir ativos em dólar na carteira.

Escopo
- [ ] Ativos e operações em moeda estrangeira (valor original + moeda)
- [ ] `exchange_rates` diárias (PTAX do Banco Central) via contrato `ExchangeRateProvider`
- [ ] Cotação de ativos do exterior via `QuoteProvider` (fornecedor a definir)
- [ ] Posição em moeda original e em BRL; variação cambial separada da variação do ativo

Critérios de aceite
- [ ] Conversão usa o câmbio da data (ou o último anterior disponível) (teste)

Notas da entrega:

---

## Bloco D — Patrimônio

### E13 — Dívidas
**Valor:** ver quanto falta pagar de cada financiamento.

Escopo
- [ ] `debts`: credor, principal, taxa mensal, sistema (price, sac, custom), número de parcelas, início, conta de pagamento
- [ ] Geração da tabela de parcelas (`debt_installments`: vencimento, amortização, juros, total)
- [ ] Parcelas geram lançamentos previstos; pagar o lançamento baixa a parcela
- [ ] Amortização extraordinária (reduz prazo ou parcela)
- [ ] Saldo devedor atual e projeção de quitação

Critérios de aceite
- [ ] Tabelas Price e SAC conferem com um simulador de referência (teste)

Notas da entrega:

### E14 — Patrimônio líquido
**Valor:** acompanhar a evolução do patrimônio mês a mês.

Escopo
- [ ] `net_worth_snapshots`: mês, total em contas, investimentos, dívidas, patrimônio líquido
- [ ] Job no último dia do mês + comando para recalcular meses passados
- [ ] Gráfico histórico e composição atual
- [ ] Visão por usuário (só o que ele vê) e do lar

Critérios de aceite
- [ ] Recalcular um mês passado gera o mesmo valor que o snapshot original (teste)

Notas da entrega:

### E15 — Metas
**Valor:** acompanhar objetivos como reserva de emergência e viagem.

Escopo
- [ ] `goals`: nome, valor-alvo, prazo, contas vinculadas
- [ ] Progresso = saldo das contas vinculadas (a confirmar: ou aportes registrados)
- [ ] Ritmo mensal necessário para bater o prazo
- [ ] Widget de metas no painel

Critérios de aceite
- [ ] Progresso e ritmo mensal corretos em cenário de teste

Notas da entrega:

---

## Pontos em aberto

- [ ] **Reativar o 2FA na entrega final:** em desenvolvimento está desligado (`AUTH_MFA_ENABLED=false` no `.env`), a pedido, para facilitar os testes. Na entrega final, voltar para `true` (padrão do `.env.example` e dos testes).

- [ ] Fornecedor de cotações do exterior (decidir na E12)
- [x] Bancos e cartões usados e formato de exportação (antes da E5): Bradesco, PicPay, Nubank e Mercado Pago — ver notas da E5; falta validar os perfis "a conferir" com arquivos reais
- [x] Destino do backup além da pasta local: Google Drive, pasta Backups, 30 dias (E8)
- [ ] Modelo de progresso das metas (antes da E15)
- [x] Layout: conteúdo usa largura máxima fixa e centralizada, deixando muito espaço entre o menu e a tabela e à direita. Proposta: `->maxContentWidth(Width::Full)` no `AppPanelProvider` (ou `ScreenTwoExtraLarge`). Aproveitar para revisar a lista de lançamentos, que hoje precisa de rolagem horizontal (ações da linha cortadas): agrupar ações em menu e ocultar colunas secundárias por padrão. **Feito após a E3:** `Width::Full`; na lista de lançamentos, só "Pagar" fica visível e o resto vai para o menu ⋯; "Pago por" e "Vencimento" ficam ocultas por padrão (dá para exibir pelo seletor de colunas).
