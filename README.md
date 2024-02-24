# nod32mirror

ESET Nod32 Updates Mirror based on nginx:stable-alpine and php script [eset_mirror_script](https://github.com/Kingston-kms/eset_mirror_script) with deleted brandings and many improvements

## Setup

Get the docker-compose file:

```sh
wget https://raw.githubusercontent.com/marat2509/nod32-mirror/main/docker-compose.yml
```

Get the configuration file:

```sh
wget https://raw.githubusercontent.com/marat2509/nod32-mirror/main/nod32ms.conf
```

## Run

```sh
docker-compose up -d
```

## Using

Open the browser and go to `http://localhost:8084/`

If the page is displayed, enter your URL in the ESET settings
