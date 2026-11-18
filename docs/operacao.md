# Operação

Todos os comandos são executados na VPS, dentro de `/root/azure-facilities`.

## Atualizar a aplicação

```bash
git pull
docker compose -f docker-compose.prod.yml up -d --build
```

O Docker constrói a nova imagem e só então troca o container. Na subida, as
migrations novas rodam sozinhas. A indisponibilidade fica em poucos segundos
(o tempo de o novo container iniciar).

Para conferir:

```bash
docker inspect -f '{{.State.Health.Status}}' azure-facilities   # healthy
docker logs --tail 50 azure-facilities
```

## Comandos do dia a dia

| Tarefa | Comando |
|---|---|
| Status e saúde | `docker ps --filter name=azure-facilities` |
| Logs (seguindo) | `docker logs -f azure-facilities` |
| Reiniciar | `docker restart azure-facilities` |
| Parar / subir | `docker compose -f docker-compose.prod.yml down` / `up -d` |
| Artisan | `docker exec azure-facilities php artisan <comando>` |
| Situação das migrations | `docker exec azure-facilities php artisan migrate:status` |
| Limpar o cache da aplicação | `docker exec azure-facilities php artisan cache:clear` |
| Console do banco | `docker exec -it postgres psql -U azure_facilities -d azure_facilities` |
| Uso de recursos | `docker stats azure-facilities` |

> `down` remove o container mas **mantém** o volume `azure-facilities_storage`
> e os dados no Postgres. Nunca use `down -v` em produção.

## Backup

O que precisa de backup:

| O quê | Onde | Como |
|---|---|---|
| Banco `azure_facilities` | Postgres compartilhado | `pg_dump` (abaixo) |
| `.env.production` | `/root/azure-facilities` | Cópia segura **fora da VPS** (gerenciador de senhas) |
| Arquivos enviados | volume `azure-facilities_storage` | `tar` do volume |
| Código | GitHub | Já versionado |

O Redis não precisa de backup: é só cache.

> **Importante:** o dump do banco sem o `AZURE_TOKEN_KEYS` correspondente não
> permite recuperar os PATs dos usuários (eles estão criptografados). Guarde as
> duas coisas.

```bash
# banco
docker exec postgres pg_dump -U azure_facilities -d azure_facilities -Fc \
  > /root/backups/azure_facilities_$(date +%F).dump

# arquivos enviados
docker run --rm -v azure-facilities_storage:/data -v /root/backups:/backup alpine \
  tar czf /backup/azure_facilities_storage_$(date +%F).tgz -C /data .
```

Para automatizar, um `cron` diário com os comandos acima e remoção dos arquivos
com mais de N dias (`find /root/backups -mtime +14 -delete`).

### Restauração

```bash
docker compose -f docker-compose.prod.yml stop
docker exec -i postgres pg_restore -U azure_facilities -d azure_facilities --clean --if-exists \
  < /root/backups/azure_facilities_AAAA-MM-DD.dump
docker compose -f docker-compose.prod.yml start
```

## Rotação das chaves dos PATs

1. Gerar a nova chave: `openssl rand -base64 32`.
2. No `.env.production`, adicionar a versão nova **mantendo a antiga** e apontar
   a atual para ela:
   `AZURE_TOKEN_KEYS="1:<antiga>,2:<nova>"` e `AZURE_TOKEN_KEY_CURRENT=2`.
3. `docker compose -f docker-compose.prod.yml up -d` (recarrega o `.env`).
4. `docker exec azure-facilities php artisan azure:rotate-tokens` — recriptografa
   todos os PATs com a chave nova.
5. Só então remover a chave `1:` do `.env.production` e subir de novo.

## Solução de problemas

| Sintoma | Causa provável | Verificação / correção |
|---|---|---|
| `502 Bad Gateway` no domínio | Container parado ou fora da rede `proxy` | `docker ps`; `docker inspect azure-facilities --format '{{json .NetworkSettings.Networks}}'` |
| Container `unhealthy` ou reiniciando | Erro na subida (ex.: banco inacessível) | `docker logs azure-facilities` |
| `SQLSTATE … connection refused` | Postgres fora do ar ou container fora da `db_net` | `docker ps --filter name=postgres` |
| `WRONGPASS` / `NOAUTH` do Redis | `REDIS_PASSWORD` errada | Conferir o `.env.production` e recriar com `up -d` |
| `419 Page Expired` ao fazer login | Acesso por HTTP ou domínio diferente do `APP_URL` (o cookie é `Secure`) | Acessar sempre por `https://azure.tonyhub.dev` |
| Links ou assets com `http://` | Proxy não envia `X-Forwarded-Proto` | Conferir o *proxy host* no NPM |
| Mudança no `.env.production` não aplicou | O container lê o arquivo só ao ser criado | `docker compose -f docker-compose.prod.yml up -d` (não basta `restart`) |
| Certificado expirado | Renovação do NPM falhou | Painel do NPM → *SSL Certificates* → *Renew* |
