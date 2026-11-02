FROM nginx:stable-alpine

COPY .docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY .docker/nginx/templates/ /etc/nginx/templates/
COPY public/ /var/www/app/public/

EXPOSE 80
