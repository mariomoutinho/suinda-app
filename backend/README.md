# Backend Suinda

API em PHP com PDO e SQLite para o app de estudos.

## Rodar localmente

```bash
php -S localhost:8000 -t backend/public
```

Ao iniciar, o backend cria `backend/database/suinda.sqlite` e popula dados iniciais.

No app Android em emulador, `localhost` aponta para o proprio emulador. Ajuste a URL da API no app com:

```js
localStorage.setItem("suinda_api_base_url", "http://10.0.2.2:8000");
```

Em celular fisico, use o IP do computador na rede local, por exemplo `http://192.168.0.10:8000`.

Usuario de teste:

- E-mail: `aluno@suinda.com`
- Senha: `123456`

Administrador de teste:

- E-mail: `admin@suinda.com`
- Senha: `admin123`

## Endpoints

- `POST /auth/login`
- `GET /me`
- `GET /decks`
- `GET /decks/{id}`
- `POST /decks`
- `GET /decks/{id}/cards`
- `POST /decks/{id}/cards`
- `PUT /cards/{id}`
- `DELETE /cards/{id}`
- `POST /import`
- `POST /import/apkg`
- `GET /study/history`
- `POST /study/sessions`
- `GET /stats/today`
- `GET /stats/daily`
- `GET /cards/progress`
- `PUT /cards/{id}/progress`

As rotas, exceto login, usam header:

```http
Authorization: Bearer SEU_TOKEN
```

## MySQL

O arquivo `database/schema.mysql.sql` tem a estrutura para MySQL/MariaDB caso voce queira migrar depois para XAMPP, WAMP ou hospedagem.
