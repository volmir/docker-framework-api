# No Framework API

## Description

No Framework API.

### Docker + PHP 8.2 + MySQL + Nginx

This is a complete stack for running web application into Docker containers using docker-compose tool.

It is composed by 3 containers:
- `nginx`, acting as the webserver.
- `php`, the PHP-FPM container with the 8.0 version of PHP.
- `db` which is the MySQL database container with a **MySQL 8.0** image.

## Installation

1. Clone this repository
2. Create the file `./.docker/.env.nginx.local` using `./.docker/.env.nginx` as template. The value of the variable `NGINX_BACKEND_DOMAIN` is the `server_name` used in Nginx.
3. Configure ports for MySQL and Nginx in file `./.docker/docker-compose.yml`
4. Go inside folder `./.docker` and run `docker compose up -d` to start containers.
5. Inside the `php` container, run `composer install` to install dependencies from `/var/www/application/vendor` folder.
6. Shutdown server. Run `docker-compose stop` and `docker-compose rm -f`

## Run PHPUnit tests

```sh
$ cd ./.docker/test
$ docker-compose -f docker-compose.test.yml run tests
```

## Database dump

See export file `db_dump.sql` in /docs directory.

## Usage Postman

Run application in Postman. See export collection in /docs directory.

## Usefull Docker commands

```sh
$ docker-compose up -d
$ docker-compose stop
$ docker-compose rm -f
```
```sh
$ docker-compose build
$ docker-compose ps
$ docker-compose exec php ls -l
$ docker-compose exec php composer install
$ docker-compose exec -it php bash
```
```sh
$ docker images -a
$ docker system prune -a
```

