# symfony-ai-agents
test symfony-ai platform and agents

 composer require symfony/ai-platform:dev-main --dev
 composer require symfony/ai-agent:dev-main --dev

 add in framework.yml:

   http_client:
    default_options:
      max_redirects: 7

 run it with:
 bin/console app:wikipedia:query --profile
