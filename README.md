# PrestaOps

_A CLI tool for all kinds of PrestaShop operations._

What can you do?
- Moves a PrestaShop to a new hosting platform
- Audits PrestaShop modules
- What more?


## Installation

...

## Commands

Run a PrestaShop modules audit, to see which modules can be updated

```bash
prestaops audit
```


Migrate a PrestaShop site to the current hosting platform
```bash
prestaops migrate
```

# Test using Docker

We've included a Docker compose file for testing the upgrade feature. With this file we can install a new PrestaShop and test the operations in the CLI-tool.


1. **Install a new PrestaShop the preferred version**

```
export PS_VERSION=1.7.7.5 && docker compose up
```

Once that is up and running;

2. **Enter the container**

```
docker compose exec apache /bin/bash
```

3. **Install system requirements**

```
apt-get update && apt-get install -y git && apt-get install -y rsync && apt-get install -y openssh-client
```

4. **Link prestaops**

```bash
ln -s /var/www/prestaops/index.php /usr/bin/prestaops
chmod +x /usr/bin/prestaops
```