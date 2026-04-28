# Ambiente local

Enquanto o app estiver em desenvolvimento, use o servidor PHP local e o banco SQLite local.

## Pasta local de dados

Os dados locais ficam em:

```text
backend/local-data/
```

Estrutura:

```text
backend/local-data/database/suinda.sqlite
backend/local-data/uploads/
backend/local-data/imports/
```

Essa pasta nao deve ser enviada para hospedagem nem versionada.

## Rodar o backend local

No PowerShell, rode:

```powershell
.\backend\start-local.ps1
```

Ou manualmente:

```powershell
$env:SUINDA_DB_DRIVER="sqlite"
$env:SUINDA_SQLITE_PATH="backend/local-data/database/suinda.sqlite"
$env:SUINDA_STORAGE_PATH="backend/local-data"
php -S 127.0.0.1:8000 -t backend/public
```

Depois abra o app pelo Live Server:

```text
http://127.0.0.1:5500/www/pages/login.html
```

## Rodar com MySQL local

Se quiser usar o banco `suinda_app` do phpMyAdmin/XAMPP em vez do SQLite:

```powershell
.\backend\start-mysql-local.ps1
```

Esse script usa:

```text
Host: 127.0.0.1
Porta: 3306
Banco: suinda_app
Usuario: root
Senha: vazia
```
