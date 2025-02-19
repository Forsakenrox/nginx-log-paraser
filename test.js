console.log(123);
var i = 0;
while (true) {
    i++;
    console.log(i);
    let log = await fetch("https://bitrix24.lab.nicct.ru/");
    console.log(log);
}
