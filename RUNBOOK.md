# SpiderNetOS Production Runbook

## Restart Stack
docker stack rm spidernet
docker stack deploy -c docker-compose.prod.yml spidernet

## View Logs
docker service logs spidernet_laravel -f

## Health Check
curl https://your-domain.com/api/health
