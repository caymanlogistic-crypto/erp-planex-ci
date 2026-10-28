const manager='Карина Г.Гладких Карина Артуровна';
export const driverTsExpectations={
  rowSelector:'.driver-ts-data-table tbody > tr',
  rows:[
    {id:9,cells:[['#345, #346, #347, #348'],['Иванов Иван+7 900 000-00-05ivanov@example.test'],['КАМАЗ А 123 АА 77· 20 т· 86 м³ТОНАР А1213 334· 24 т· 92 м³'],[manager]]},
    {id:12,cells:[['#351'],['Петрова Мария+7 900 000-00-16petrova@example.test'],['ГАЗОН NEXT М 814 КК 16· 5 т· 22 м³'],['Владимир С.Владимир С.']]},
    ...Array.from({length:35},(_,offset)=>{
      const id=20+offset;
      return {id,cells:[[`#${400+id}`],[`Тестовый водитель ${id}+7 900 000-${id}driver${id}@example.test`],[`КАМАЗ А ${String(id).padStart(3,'0')} ВС 77· 20 т· 86 м³`],[manager]]};
    }),
  ],
  probes:[{query:'#351',ids:[12]},{query:'Тестовый водитель 27',ids:[27]},{query:'несуществующий рейс',ids:[]}],
};
