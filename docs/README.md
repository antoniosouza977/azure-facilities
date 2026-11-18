# Documentação — Azure Facilities

Documentação técnica do **Azure Facilities**, projeto de extensão (Projeto de
Extensão V — Análise e Desenvolvimento de Sistemas).

Para as funcionalidades da aplicação e o ambiente de desenvolvimento, veja o
[README principal](../README.md). Esta pasta trata da **infraestrutura**: como a
aplicação roda em produção e se integra aos outros serviços da VPS.

| Documento | Conteúdo |
|---|---|
| [Infraestrutura](infraestrutura.md) | Visão geral, diagrama, servidor, serviços compartilhados, redes e fluxo de uma requisição |
| [Deploy](deploy.md) | Imagem Docker, `docker-compose.prod.yml`, variáveis de ambiente e passo a passo da primeira instalação |
| [Operação](operacao.md) | Atualização, logs, comandos do dia a dia, backup e restauração, solução de problemas |
| [Segurança](seguranca.md) | Camadas de proteção, segredos, criptografia dos PATs e recomendações |

## Resumo

- **Endereço:** <https://azure.tonyhub.dev>
- **Hospedagem:** VPS Ubuntu 24.04 com Docker, compartilhada com outras aplicações.
- **Aplicação:** um único container (`azure-facilities`) com PHP-FPM + Nginx.
- **Reaproveita da VPS:** Postgres, Redis e o Nginx Proxy Manager (TLS/HTTPS),
  que já atendiam outras aplicações. Nenhum desses serviços foi duplicado.
- **Isolamento:** banco e usuário próprios no Postgres; bancos e prefixo de
  chaves próprios no Redis; container só acessível pelas redes internas do Docker.
