var f = document.forms.votetv;
f.onchange = function() {
    var n = f.querySelectorAll('[type="checkbox"]'), 
        l = f.querySelectorAll('[type="checkbox"]:checked');
    for(var j=0; j<n.length; j++)
        if (l.length >= 2) { // если отметить не менее 2х галочек
            n[j].disabled = true; // все чекбоксы становятся disabled
            f.querySelector('[type="submit"]').disabled = false; //активируем кнопку
            for(var i=0; i<l.length; i++)
                l[i].disabled = false; // но disabled убирается с помеченных галочками чекбоксов
        } 
        else {
            n[j].disabled = false; // если выделить менее трёх галочек, то disabled снимается со всех чекбоксов
            f.querySelector('[type="submit"]').disabled = true; //деактивируем кнопку
        }
}