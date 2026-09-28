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

Versões instaladas (preencher na E1): PHP `__` · Laravel `__` · Filament `__` · PostgreSQL `__`

---

## Bloco A — Dia a dia

### E1 — Livro-caixa mínimo
**Valor:** registrar receitas e despesas e ver o saldo de cada conta.

Escopo
- [ ] Projeto Laravel + Filament + PostgreSQL em Docker (app, db, queue, scheduler)
- [ ] Pest, Pint e Larastan configurados; script `composer test`
- [ ] Tabelas `households`, `household_user` (papel: admin, member); global scope `BelongsToHousehold`
- [ ] Login no Filament, sem registro público; 2FA obrigatório
- [ ] Comando `app:create-household` e `app:add-member` para criar o lar e os usuários
- [ ] `accounts`: nome, tipo (checking, savings, cash, credit_card, brokerage), `owner_id`, `visibility` (private, shared), `currency`, `initial_balance`, `archived_at`
- [ ] `categories`: nome, tipo (income, expense), `parent_id` (máx. 2 níveis), cor; categorias padrão criadas junto com o lar
- [ ] `transactions`: conta, categoria, `amount` (centavos, com sinal), `date`, `competence_date`, `description`, `paid_by`, `notes`, tags
- [ ] Resource de lançamentos com filtros (conta, categoria, período, pago por) e lançamento rápido
- [ ] Saldo atual por conta (inicial + lançamentos) no painel
- [ ] Backup diário do Postgres (`pg_dump` compactado, retenção de 30 dias em pasta local)
- [ ] `DemoSeeder` com um lar, 2 usuários e dados de exemplo

Critérios de aceite
- [ ] Usuário A não vê contas privadas nem lançamentos das contas privadas do usuário B (teste)
- [ ] Ambos veem e editam contas compartilhadas (teste)
- [ ] Saldo da conta confere com a soma dos lançamentos (teste)
- [ ] Restauração do backup testada manualmente e documentada no README

Notas da entrega:

### E2 — Contas a pagar
**Valor:** saber o que vence nos próximos dias.

Escopo
- [ ] `status` do lançamento (scheduled, paid) e `due_date`; saldo considera só os pagos, com saldo projetado à parte
- [ ] Ação "marcar como pago" (individual e em massa), podendo ajustar data e valor
- [ ] Transferências entre contas (`transfer_id` ligando o par); excluídas de relatórios de receita/despesa
- [ ] Widget no painel: atrasados, vencendo em 7 dias, total previsto do mês

Critérios de aceite
- [ ] Transferência gera exatamente 2 lançamentos de sinais opostos e editar/excluir um afeta o par (teste)
- [ ] Lançamento vencido e não pago aparece como atrasado (teste)

Notas da entrega:

### E3 — Contas fixas
**Valor:** parar de relançar aluguel, luz e salário todo mês.

Escopo
- [ ] `recurrences`: modelo do lançamento, frequência (mensal, semanal, anual, a cada N meses), dia, próxima data, data fim, valor fixo ou estimado
- [ ] Job diário gera os lançamentos previstos até 60 dias à frente, sem duplicar
- [ ] Editar recorrência pergunta se altera só os futuros ainda não pagos
- [ ] Lançamento gerado aponta para a recorrência de origem

Critérios de aceite
- [ ] Rodar o job duas vezes não duplica lançamentos (teste)
- [ ] Dia 31 em meses curtos cai no último dia do mês (teste)

Notas da entrega:

### E4 — Cartão por fatura
**Valor:** ver a fatura aberta e as próximas, com parcelas no mês certo.

Escopo
- [ ] `credit_cards` (1:1 com conta do tipo credit_card): dia de fechamento, dia de vencimento, limite
- [ ] `invoices`: cartão, mês de referência, data de fechamento, vencimento, status (open, closed, paid); criadas sob demanda
- [ ] Regra: compra em data ≥ fechamento cai na fatura seguinte; `competence_date` = mês da fatura
- [ ] `installment_groups`: compra parcelada gera N lançamentos, um por fatura seguinte, com "3/10" na descrição; última parcela absorve o arredondamento
- [ ] Estornos (valor positivo) na fatura
- [ ] Pagamento de fatura = transferência da conta corrente para o cartão; marca a fatura como paga
- [ ] Tela da fatura: itens, total, limite disponível, próximas faturas com parcelas já comprometidas
- [ ] Vencimento da fatura aparece no widget de contas a pagar

Critérios de aceite
- [ ] Compra no dia do fechamento cai na fatura correta (teste com várias combinações de dias)
- [ ] 1000,00 em 3x gera 333,33 + 333,33 + 333,34 (teste)
- [ ] Soma das parcelas futuras reduz o limite disponível (teste)

Notas da entrega:

### E5 — Importação de extratos
**Valor:** trazer o mês do banco em minutos, sem digitar.

Escopo
- [ ] Upload de OFX e CSV associado a uma conta (ou cartão)
- [ ] Mapeamento de colunas do CSV salvo por banco/conta (data, descrição, valor, formato de data e decimal)
- [ ] `import_batches` e hash por linha (conta + data + valor + descrição normalizada) para deduplicação
- [ ] `import_rules`: "descrição contém X" → categoria (e opcionalmente descrição amigável)
- [ ] Tela de revisão antes de gravar: nova, duplicada, possível correspondência com lançamento previsto (mesmo valor ± 3 dias)
- [ ] Ao confirmar correspondência, o previsto vira pago em vez de criar outro lançamento
- [ ] Criar regra a partir de uma linha na revisão

Critérios de aceite
- [ ] Importar o mesmo arquivo duas vezes não duplica nada (teste)
- [ ] Previsto correspondente é baixado, não duplicado (teste)
- [ ] Testes com arquivos OFX/CSV de exemplo em `tests/Fixtures`

Notas da entrega:

---

## Bloco B — Visão e controle

### E6 — Painel do mês
**Valor:** entender para onde foi o dinheiro.

Escopo
- [ ] Seletor de mês; entradas × saídas por competência (sem transferências)
- [ ] Gastos por categoria (pai e filha)
- [ ] Evolução dos últimos 12 meses
- [ ] Projeção de saldo das contas até o fim do mês com base nos previstos
- [ ] Todos os números respeitam a visibilidade do usuário

Critérios de aceite
- [ ] Totais do painel batem com a soma dos lançamentos filtrados (teste)

Notas da entrega:

### E7 — Orçamento
**Valor:** saber se está gastando além do combinado.

Escopo
- [ ] `budgets`: categoria, mês, valor; orçamento na categoria pai soma as filhas
- [ ] Copiar orçamento do mês anterior
- [ ] Tela orçado × realizado com barra de progresso; destaque a partir de 80% e acima de 100%
- [ ] Widget no painel com as categorias estouradas

Critérios de aceite
- [ ] Realizado usa `competence_date` (compra no cartão conta no mês da fatura) (teste)

Notas da entrega:

### E8 — Comprovantes
**Valor:** achar a nota ou o boleto de qualquer lançamento.

Escopo
- [ ] Conexão OAuth com a conta Google do lar; pasta raiz configurável
- [ ] Disco `google` via adapter Flysystem; arquivos organizados em `ano/mês`
- [ ] `attachments`: lançamento, id do arquivo no Drive, nome, tipo, tamanho
- [ ] Upload e visualização a partir do lançamento; indicador de anexo na listagem
- [ ] Opcional: backup diário da E1 também enviado ao Drive

Critérios de aceite
- [ ] Anexo de lançamento em conta privada só é acessível ao dono (teste)
- [ ] Integração testada com fake do disco

Notas da entrega:

---

## Bloco C — Investimentos

### E9 — Carteira B3
**Valor:** ver posição e preço médio de ações, FIIs, ETFs e BDRs.

Escopo
- [ ] `assets`: tipo, ticker, nome, moeda, conta da corretora
- [ ] `asset_operations`: compra/venda, quantidade, preço, taxas, data; gera lançamento na conta da corretora
- [ ] Serviço de posição: quantidade, preço médio (inclui taxas; venda não altera o PM), custo total
- [ ] Desdobramentos e grupamentos como operação especial que ajusta quantidade e PM
- [ ] Contrato `QuoteProvider` + implementação para B3 (avaliar brapi) + job diário de cotações em `asset_prices`
- [ ] Ajuste manual de cotação
- [ ] Tela da carteira: posição, valor de mercado, resultado não realizado, distribuição por tipo

Critérios de aceite
- [ ] Preço médio correto em sequência de compras, vendas parciais e desdobramento (testes)
- [ ] Falha da API não quebra o job; posição usa a última cotação disponível (teste)

Notas da entrega:

### E10 — Proventos e rentabilidade
**Valor:** saber quanto a carteira rende de verdade.

Escopo
- [ ] `asset_incomes`: dividendo, JCP, rendimento; gera receita na conta da corretora
- [ ] Resultado realizado nas vendas
- [ ] Rentabilidade por ativo e total (valorização + proventos) por período
- [ ] Série diária do CDI (API SGS do Banco Central) para comparação

Critérios de aceite
- [ ] Rentabilidade confere com cálculo manual em cenário de teste

Notas da entrega:

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

- [ ] Fornecedor de cotações do exterior (decidir na E12)
- [ ] Bancos e cartões usados e formato de exportação (antes da E5)
- [ ] Destino do backup além da pasta local (proposta: Google Drive na E8)
- [ ] Modelo de progresso das metas (antes da E15)
