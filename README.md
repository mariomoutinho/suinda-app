# Suinda App

Aplicativo de estudos com flashcards, baralhos personalizados e logica de repeticao espacada.

## Estrutura

- `www/`: interface web usada pelo navegador e pelo Capacitor.
- `backend/`: API PHP com PDO e SQLite local por padrao.
- `android/`: projeto Android gerado pelo Capacitor.
- `tools/`: scripts auxiliares do projeto.

## Rodar localmente

Suba o backend:

```powershell
php -S 127.0.0.1:8013 -t backend/public
```

Suba o frontend:

```powershell
php -S 127.0.0.1:8012 -t www
```

Acesse:

```text
http://127.0.0.1:8012
```

O frontend usa `http://127.0.0.1:8013` como API local padrao. Para sobrescrever:

```js
localStorage.setItem("suinda_api_base_url", "http://SEU_HOST:PORTA");
```

Usuarios de teste:

- `aluno@suinda.com` / `123456`
- `admin@suinda.com` / `admin123`
