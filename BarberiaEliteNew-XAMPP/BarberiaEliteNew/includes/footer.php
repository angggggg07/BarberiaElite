</main>
<footer class="site-footer">
    <div class="<?= $isDashboard ? 'workspace-footer' : 'public-width public-footer' ?>">
        <span>Barberia Elite · Bari</span>
        <?php if (!$isDashboard): ?>
            <a href="<?= e(appUrl('index.php#accesso')) ?>">Area riservata</a>
        <?php endif; ?>
    </div>
</footer>
</body>
</html>
