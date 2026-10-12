<?php
require __DIR__.'/app/bootstrap.php';
start_session();
$error = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = post('action');
        if ($action === 'logout') { $_SESSION = []; session_regenerate_id(true); redirect('account.php'); }
        if ($action === 'login' || $action === 'register') {
            limit_attempt($action.'-ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $action === 'login' ? 30 : 5, 900);
            $email = strtolower(text_field('email', 190));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Укажите корректный email.');
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || strlen($password) > 72) throw new DomainException('Пароль слишком длинный (максимум 72 байта).');
            if ($action === 'register') {
                if ((iconv_strlen($password, 'UTF-8') ?: 0) < 12) throw new DomainException('Пароль должен содержать не менее 12 символов.');
                $name = text_field('name', 190);
                $apartment = text_field('apartment', 32);
                try {
                    query('INSERT INTO users (email, name, apartment, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$email, $name, $apartment, password_hash($password, PASSWORD_DEFAULT), 'owner', 'pending', now()]);
                } catch (PDOException $e) { if ((string)$e->getCode() !== '23000') throw $e; }
                notice('Если адрес ещё не зарегистрирован, заявка принята. Для доступа к обсуждениям дождитесь подтверждения ТСЖ.');
                redirect('account.php');
            }
            limit_attempt('login-email', $email, 10, 900);
            $u = query('SELECT * FROM users WHERE email = ?', [$email])->fetch();
            // A fixed valid hash keeps unknown-account failures on the password-verification path.
            $hash = $u['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            if (!password_verify($password, $hash) || !$u || $u['status'] === 'blocked') throw new DomainException('Неверный email или пароль.');
            session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirect('account.php');
        }
        if ($action === 'password') {
            $u = user();
            if (!$u) throw new DomainException('Сначала войдите.');
            limit_attempt('password', (string)$u['id'], 10, 900);
            $old = $_POST['old_password'] ?? '';
            $new = $_POST['password'] ?? '';
            if (!is_string($old) || !password_verify($old, $u['password_hash'])) throw new DomainException('Текущий пароль неверен.');
            if (!is_string($new) || (iconv_strlen($new, 'UTF-8') ?: 0) < 12 || strlen($new) > 72) throw new DomainException('Новый пароль: от 12 символов, не более 72 байт.');
            query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            notice('Пароль изменён.'); redirect('account.php');
        }
    }
    $current = user();
} catch (Throwable $e) { $error = $e; $current = null; }
page_start('Личный кабинет');
if ($error) render_error($error);
if ($current): ?>
<p>Здравствуйте, <?=h($current['name'])?>.</p>
<p>Статус: <?=is_owner($current) ? 'Доступ подтверждён' : 'Ожидает проверки администратором ТСЖ'?>.</p>
<?php if (is_admin($current)): ?><p><a href="admin.php">Управление новостями и заявками</a></p><?php endif ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="logout"><button>Выйти</button></form>
<h2>Изменить пароль</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="password">
<label>Текущий пароль<input type="password" name="old_password" autocomplete="current-password" required></label>
<label>Новый пароль<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><button>Изменить пароль</button></form>
<?php else: ?>
<h2>Вход</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="login">
<label>Email<input type="email" name="email" maxlength="190" autocomplete="username" required></label>
<label>Пароль<input type="password" name="password" autocomplete="current-password" required></label><button>Войти</button></form>
<h2>Заявка собственника</h2><p>Администратор ТСЖ проверит принадлежность квартиры перед предоставлением доступа. Номер квартиры сам по себе не подтверждает право собственности.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="register">
<label>Имя и фамилия<input name="name" maxlength="60" autocomplete="name" required></label>
<label>Квартира<input name="apartment" maxlength="10" required></label>
<label>Email<input type="email" name="email" maxlength="190" autocomplete="email" required></label>
<label>Пароль (от 12 символов)<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
<p>Данные заявки будут доступны администратору ТСЖ. В обсуждениях отображается ваше имя; email и номер квартиры скрыты.</p><button>Отправить заявку</button></form>
<?php endif; page_end();