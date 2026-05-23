    </main><!-- /.app-main -->

    <footer class="app-footer">
        <span>Group 7 — Transaction / Request Management Subsystem &nbsp;·&nbsp; Southland College</span>
        <span>Teacher Portal &nbsp;·&nbsp; Logged in as <strong><?php echo e($_SESSION['t_name'] ?? ''); ?></strong></span>
    </footer>

</div><!-- /.app-shell -->

<script>
// Live clock
(function tick(){
    const el = document.getElementById('liveClock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    setTimeout(tick, 1000);
})();

// Auto-dismiss alerts
document.querySelectorAll('.alert.alert-success,.alert.alert-info').forEach(el => {
    setTimeout(() => { el.style.transition='opacity .5s'; el.style.opacity='0'; setTimeout(()=>el.remove(),500); }, 5000);
});
</script>
</body>
</html>
