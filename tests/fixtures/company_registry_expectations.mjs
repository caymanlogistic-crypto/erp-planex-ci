// Independent acceptance oracle for company_registry_canon_render.php.
// Explicit expected field roles/values: deleting a rendered field must not shrink the test.
export function companyRegistryExpectations(kind, role='company_owner') {
  const rowSelector=`.registry-ui-card tbody tr[data-${kind==='contractors'?'contractor':'vehicle-set'}-id]`;
  const rows=Array.from({length:35},(_,i)=>{
    const id=40+i;
    const cells=kind==='contractors'
      ? [[String(id)],[['ООО «Восточный маршрут»','ИП Крылов','ТК «Северный ветер»'][i%3], 'ИНН 7701234567 · '+['Юридическое лицо','ИП','Физическое лицо'][i%3]],
          [i===1?'—':'Мария Волкова',i===1?'contact@example.test':'+7 900 123-45-67'],
          ...(role==='company_owner'? [['Анна Петрова']]:[]),[i===1?'Неактивен':'Активен']]
      : [[String(id)],[i%2?'Одиночка':'Сцепка'],['КамАЗ 5490 А123АА77'],[i===1?'—':'88.5'],['20'],
          [i%2?'Есть':'НЕТ'],[i%2?'НЕТ':'Есть'],[i%2?'Есть':'НЕТ'],
          [i%2?'—':'Тонар 9888 В456ВВ77'],[i%2?'—':'90'],[i%2?'—':'25'],
          [i%2?'—':'Есть'],[i%2?'—':'НЕТ'],[i%2?'—':'Есть']];
    return {id,cells};
  });
  const queries=kind==='contractors'
    ? ['Активен','Неактивен','Юридическое лицо','ИП','Физическое лицо','ИНН 7701234567','Мария Волкова','+7 900 123-45-67','contact@example.test','Анна Петрова']
    : ['Есть','НЕТ','Одиночка','Сцепка','КамАЗ 5490 А123АА77','Тонар 9888 В456ВВ77','88.5','25'];
  return {rowSelector,rows,forbidden:role==='company_owner'?[]:['Анна Петрова'],
    probes:[...queries,'нет такого значения 918273'].map(query=>({query,ids:rows.filter(row=>row.cells.flat().join(' ').toLocaleLowerCase('ru').includes(query.toLocaleLowerCase('ru'))).map(row=>row.id)}))};
}
