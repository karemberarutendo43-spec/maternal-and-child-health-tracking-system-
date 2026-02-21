<?php
header("Location: /mch_system/auth/login.php");
exit;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0; url=/mch_system/auth/login.php">
    <title>MCHTS Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 flex items-center justify-center min-h-screen">

    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
        <h1 class="text-2xl font-bold text-center text-blue-700 mb-2">
            Maternal & Child Health System
        </h1>
        <p class="text-center text-gray-500 mb-6">
            Nurse / Admin Login
        </p>

        <?php if (isset($_GET['error'])): ?>
  <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm">
    Invalid username or password
  </div>
<?php endif; ?>


        <form method="POST" action="auth/login.php" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" required
                    class="w-full mt-1 p-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" required
                    class="w-full mt-1 p-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded-lg">
                Login
            </button>
        </form>

        <p class="text-center text-xs text-gray-400 mt-6">
            © District Hospital – MCHTS
        </p>
    </div>

</body>

</html>
