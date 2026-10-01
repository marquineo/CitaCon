export function thisMonday(): string {
  const now = new Date();
  const day = now.getDay(); // 0 dom, 1 lun
  const diffToMonday = day === 0 ? -6 : 1 - day;
  const monday = new Date(now);
  monday.setDate(now.getDate() + diffToMonday);
  return monday.toISOString().slice(0, 10);
}

export function nextMonday(): string {
  const thisMon = thisMonday();
  const d = new Date(thisMon);
  d.setDate(d.getDate() + 7);
  return d.toISOString().slice(0, 10);
}
