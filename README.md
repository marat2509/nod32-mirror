# nod32mirror

ESET Nod32 Updates Mirror based on nginx:mainline-alpine, alpine:latest and php script [eset_mirror_script](https://github.com/Kingston-kms/eset_mirror_script) with deleted brandings and many improvements

## Setup

Get the docker-compose file:

```sh
wget https://raw.githubusercontent.com/marat2509/nod32-mirror/main/docker-compose.yml
```

Get the configuration file:

```sh
wget https://raw.githubusercontent.com/marat2509/nod32-mirror/main/nod32-mirror.yaml
```

## Run

```sh
docker-compose up -d
```

## Using

Open the browser and go to `http://localhost:8084/`

If the page is displayed, enter your URL in the ESET settings

## Storage

The worker stores newly downloaded update files in content-addressed storage
under `script.storage.dir` and publishes compatible paths into `webDir`.

Default config:

```yaml
script:
  storage:
    dir: storage
    hash: sha256
    link_method: hardlink
    gc:
      enabled: false
```

`link_method` can be `hardlink`, `softlink`, or `copy`. The canonical
metadata file is `data/content-index.json`.

Schema (published paths are relative to `webDir`):

```json
{
	"hash_algorithm": "sha256",
	"updated_at": "2026-05-20T12:34:56+00:00",
	"hashes": {
		"abcdef": {
			"hash": "abcdef",
			"size": 104857600,
			"blob_path": "/app/storage/blobs/sha256/ab/cd/abcdef",
			"published_paths": ["eset_upd/ep9/production/base/defs.nup"],
			"version_ids": { "ep9": ["production"] },
			"created_at": "2026-05-20T12:00:00+00:00",
			"updated_at": "2026-05-20T12:34:56+00:00"
		}
	},
	"published": {}
}
```
