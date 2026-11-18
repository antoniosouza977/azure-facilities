# Azure Facilities

Facilitador do dia a dia do dev no Azure DevOps (Projeto de Extensão V – ADS).

- **Pull Requests** — lista unificada dos PRs em que você é autor ou revisor, em
  todos os projetos e repositórios da organização, com votos dos revisores,
  conflitos, "aguardando meu review" e link para o card. Atualiza sozinha a
  cada 10 s.
- **PBIs e Bugs** — os cards atribuídos a você ou criados por você, em todos os
  projetos, com filtros por tipo, status (inclusive os status personalizados do
  processo), projeto e busca, paginados no servidor.
- **Tokens do Azure** — cada usuário gerencia os próprios PATs (criptografados).
- *(próximo)* Criação de tasks por template (daily, refinamento, reuniões…).

Stack: Laravel 12 · Inertia 2 · Vue 3 + TypeScript · Tailwind · PostgreSQL · Redis · Docker.

## Desenvolvimento

```bash
cp .env.example .env                       # DB_PASSWORD=secret casa com o compose
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan azure:generate-key   # cole em AZURE_TOKEN_KEYS="1:<chave>"
docker compose up -d                       # app, vite, pgsql, redis
docker compose exec app php artisan migrate
```

- App: http://localhost:8000 · Vite (HMR): http://localhost:5173
- Testes: `docker compose exec app php artisan test`
- Estilo PHP: `docker compose exec app vendor/bin/pint`

O cadastro de usuários é restrito aos domínios de `ALLOWED_EMAIL_DOMAINS`.

> Se mudar `DB_PASSWORD` depois do primeiro `up`, recrie o volume do Postgres
> (`docker compose down -v`, **apaga os dados**): a senha só é aplicada na
> criação do banco.

## Funcionalidades

### Pull Requests (`/pull-requests`)

- Uma consulta por papel (autor / revisor) no nível da organização, então todos
  os projetos e repositórios entram sem configuração.
- Filtros no servidor: papel, status do PR e token; no navegador: busca,
  projeto e "aguardando meu review".
- **Atualização automática a cada 10 s** (`usePoll` do Inertia), sem mexer nos
  filtros, na busca nem na rolagem, e sem o indicador de carregamento. O
  Inertia reduz a frequência quando a aba fica em segundo plano.
- O cache da listagem (`AZURE_PR_CACHE_TTL`, padrão 5 s) precisa ser **menor
  que o intervalo do polling**; senão cada ciclo devolve o mesmo resultado.
  Cada ciclo custa 2 requisições ao Azure por aba aberta.

### PBIs e Bugs (`/work-items`)

Lista os *Product Backlog Items* e *Bugs* ligados ao dono do token, em todos os
projetos da organização, do alterado mais recentemente para o mais antigo.

| Filtro | Opções | Onde é aplicado |
|---|---|---|
| Vínculo | Todos · Atribuídos a mim · Criados por mim | WIQL (`@Me` = dono do PAT) |
| Tipo | PBIs e Bugs · Somente PBIs · Somente Bugs | WIQL |
| Status | Em aberto · Concluídos · Removidos · Todos | WIQL, pela **categoria** do estado |
| Estados | Multiselect com os estados reais do processo (`state[]=A&state[]=B`) | WIQL (`[System.State] IN (…)`) |
| Projeto | Projetos da organização | WIQL |
| Busca | Título ou tag; um número (`218834` ou `#218834`) também busca pelo id | WIQL (`CONTAINS`) |

**Status personalizados.** Os nomes dos estados variam por processo (ex.: *New,
In Progress, Disponível QA, Pronto Homologação, Homologação, Homologado, Done,
Removed*). Por isso a app lê os processos da organização
(`_apis/work/processes`) e, de cada um, os estados de PBI e Bug com a
**categoria** e a **cor** definidas no Azure. Os grupos de status saem dessas
categorias, sem nada fixo no código:

| Grupo | Categorias do Azure |
|---|---|
| Em aberto | Proposed, InProgress, Resolved |
| Concluídos | Completed |
| Removidos | Removed |

- Um estado novo criado no processo entra no grupo certo automaticamente.
  "Em aberto" é montado como *NOT IN (Concluídos + Removidos)*, então nem um
  estado que ainda não está no cache some da lista.
- O catálogo de estados e projetos fica 1 h em cache; o botão **Atualizar** o
  recarrega na hora.
- Estados ocultos no processo e de outros tipos (Task, Feature…) são ignorados;
  processos sem projeto não são consultados.
- Se a API de processos falhar, a tela usa os estados padrão do Scrum/Agile e
  esconde o filtro de projeto, mas a listagem continua funcionando.
- O multiselect só oferece os estados do grupo escolhido, na ordem do fluxo, com
  a cor do Azure. Ele espera 0,5 s após o último clique antes de consultar.
  Estados desconhecidos ou de outro grupo são descartados pelo servidor.

**Paginação no servidor** (25 por página):

1. A WIQL devolve só os ids (até `AZURE_WI_LIMIT`, padrão 5.000; a WIQL aceita
   até 20.000), que ficam em cache por `AZURE_WI_CACHE_TTL` (padrão 60 s).
2. Os detalhes são buscados (`workitemsbatch`) **apenas para os ids da página
   aberta**, e cada página também fica em cache.
3. Trocar de página não repete a WIQL; mudar qualquer filtro volta para a
   página 1; página além do fim cai na última.

**Segurança da WIQL.** Vínculo, tipo e status são validados contra listas fixas;
estados precisam existir no catálogo do processo; projeto e busca são texto
livre e têm as aspas simples escapadas no padrão da WIQL (`'` → `''`). Os testes
cobrem tentativas de injeção.

### Configuração (`.env`)

| Variável | Padrão | Para quê |
|---|---|---|
| `AZURE_PR_CACHE_TTL` | 5 | Cache (s) da lista de PRs; manter abaixo de 10 por causa do polling |
| `AZURE_WI_CACHE_TTL` | 60 | Cache (s) dos ids e das páginas de PBIs/Bugs |
| `AZURE_WI_LIMIT` | 5000 | Máximo de PBIs/Bugs por consulta (acima disso aparece o aviso de limite) |
| `AZURE_PR_LIMIT` | 200 | Máximo de PRs por papel |
| `AZURE_HTTP_TIMEOUT` | 15 | Timeout (s) das chamadas ao Azure |

O botão **Atualizar** das duas telas ignora o cache, com limite de 10 vezes por
minuto por usuário.

## Como os PATs são protegidos

| Camada | Medida |
|---|---|
| Em repouso | XChaCha20-Poly1305 (libsodium, AEAD) com nonce aleatório por registro |
| Chave | `AZURE_TOKEN_KEYS`, **separada da `APP_KEY`**; fica só no `.env` do servidor, nunca no banco nem no repositório |
| Vínculo | Associated data = id do token + id do usuário: ciphertext copiado para outra linha/usuário não decripta |
| Rotação | Chaves versionadas (`1:…,2:…`). Aponte `AZURE_TOKEN_KEY_CURRENT` para a nova e rode `php artisan azure:rotate-tokens` |
| Exposição | O PAT nunca volta ao navegador (só os 4 últimos caracteres); `ciphertext` fica em `$hidden`; clientes HTTP não serializam e usam `#[SensitiveParameter]` (não aparece em stack traces) |
| Acesso | Tela de tokens exige reconfirmar a senha; toda consulta é escopada ao usuário logado (token de outro usuário = 404); rate limit nas ações |
| Validação | O PAT é testado no Azure (`connectionData`) antes de ser salvo e a identidade do dono é registrada |
| Sessão | `SESSION_ENCRYPT=true`; em produção usar `SESSION_SECURE_COOKIE=true` atrás do HTTPS |

Escopos mínimos recomendados para o PAT:

| Escopo | Usado em |
|---|---|
| **Code (Read)** | Pull Requests |
| **Work Items (Read)** | PBIs e Bugs (WIQL, detalhes, processos e estados) |
| **Work Items (Read & Write)** | *(futuro)* criação de tasks |

Prefira validade curta. Sem o escopo de Work Items, a tela de PBIs e Bugs mostra
um aviso pedindo o escopo, e a de PRs continua funcionando.

> Backup da `AZURE_TOKEN_KEYS`: sem ela os tokens salvos ficam ilegíveis (os
> usuários teriam de cadastrá-los de novo). Guarde-a num cofre de senhas.

## Produção (VPS)

`docker-compose.prod.yml` é um rascunho: roda só o app (PHP-FPM + Nginx na
porta 8080, usuário sem privilégios, migrations automáticas no start) nas redes
externas do Nginx Proxy Manager e do Postgres/Redis já existentes. Será
detalhado na etapa de deploy.
