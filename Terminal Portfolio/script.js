// ميزة التنقل بين المدخلات باستخدام الأسهم (أعلى وأسفل)
document.addEventListener('keydown', function(event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        // جلب جميع الحقول القابلة للكتابة
        const inputs = Array.from(document.querySelectorAll('.nav-input, button[type="submit"]'));
        const activeIndex = inputs.indexOf(document.activeElement);

        if (activeIndex > -1) {
            let nextIndex = 0;
            if (event.key === 'ArrowDown') {
                nextIndex = activeIndex + 1; // السهم للأسفل
                if (nextIndex >= inputs.length) nextIndex = 0; // العودة للأول
            } else if (event.key === 'ArrowUp') {
                nextIndex = activeIndex - 1; // السهم للأعلى
                if (nextIndex < 0) nextIndex = inputs.length - 1; // الذهاب للأخير
            }
            inputs[nextIndex].focus();
            event.preventDefault(); // منع نزول الصفحة
        }
    }
});