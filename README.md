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

## Hash map

Optional hash-based reuse can be enabled via `script.hash_map.enabled` in the config.
When enabled, the worker maintains `data/hash-map.json` with hashes of files
under `webDir` and prefers reusing files by hash rather than by name/size.

Hash algorithm is configurable via `script.hash_map.algorithm` (any value supported by `hash_algos()`).
Exclude patterns support `*`, `?`, and `**` (globstar), for example `some_path/*` or `**/*.nup`.

Schema (paths are relative to `webDir`):

```json
{
	"algorithm": "xxh3",
	"files": {
		"eset_upd/v16/base/em002_64.nup": {
			"hash": { "xxh3": "9f12a3c0e5b7d4a1" },
			"size": 402653184,
			"provides": { "versions": ["v16"], "files": ["eset_upd/v10/base/em002_64.nup"] }
		},
		"deferred/eset_upd/ep9/base/defs.nup": {
			"hash": { "xxh3": "0f1e2d3c4b5a6978" },
			"size": 104857600,
			"provides": { "versions": ["ep9"], "files": [] }
		},
		"index.json": {
			"hash": { "xxh3": "1234567890abcdef" },
			"size": 20480,
			"provides": { "versions": [], "files": [] }
		}
	},
	"updated_at": 1710270000
}
```
