# 原因分析：ALLBET95MS(KM)MYR 在自己 id_product 下 formula 显示 [ALLBET95MS(KM)MYR,6] 而非 $6

## 现象
在 Edit Formula 中编辑 **ALLBET95MS(KM)MYR**，从 Data 下拉或下方数据格选本行金额（如 [6] 3318.03）时，公式被写成 `[ALLBET95MS(KM)MYR,6]`，期望应为 `$6`。

## 结论（根因）
**当前编辑行** 的判定依赖 `getRowLabelFromProcessValue(currentIdProduct)` 得到的 `currentRowLabel`。当 Data Capture 表里同一 base 有多行（如 A=ALLBET95MS(SV)MYR、B=ALLBET95MS(KM)MYR、C=ALLBET95MS(SEXY)MYR）且表里 id_product 带空格（如 `"ALLBET95MS (KM) MYR"`）时，**findProcessRow 会先用「精确匹配」再退回到「归一化匹配」，并返回「第一个」归一化匹配行**。  
若第一行是 A（SV），则 `currentRowLabel = "A"`，而用户实际选的是 B 行（KM）的格子，`clickedRowLabel = "B"`，导致被当成「其他行」，插入 `[id_product, 6]` 而不是 `$6`。

---

## 1. 插入公式时的「当前行」判定（insertCellValueToFormula）

- **位置**：`datacapturesummary.js` 中 `insertCellValueToFormula(cell)`（约 5436 行起）。
- **逻辑**：
  - `currentIdProduct` = `#process`（Id Product 输入框）的值，如 `ALLBET95MS(KM)MYR`。
  - `idProduct` = 被点击/选中的那一格所在行的 id_product（来自 `capturedTableBody` 的该行）。
  - `currentRowLabel` = `getRowLabelFromProcessValue(currentIdProduct)`。
  - `clickedRowLabel` = 被点击行在 Data Capture 表里的 row header（如 A、B、C）。
  - `isCurrentRow` = `idProductMatches && rowLabelMatches`；只有为 true 时才会插入 `$列号`，否则插入 `[id_product, 列号]`。

因此，只要 **currentRowLabel 与 clickedRowLabel 不一致**，就会被判成「其他行」，从而出现 `[ALLBET95MS(KM)MYR,6]`。

---

## 2. currentRowLabel 从哪里来：getRowLabelFromProcessValue

- **位置**：约 4072 行。
- **逻辑**：用 `findProcessRow(parsedTableData, processValue)` 在 **表数据** 里找到「当前编辑 id_product 对应的那一行」，再取该行的 **第一个单元格（row header）** 作为 row label（A/B/C 等）。
- 因此：**currentRowLabel 完全由 findProcessRow 返回的是「哪一行」决定**。若返回的是 A 行，则 currentRowLabel 永远是 "A"，即使用户编辑的是 B 行（KM）。

---

## 3. findProcessRow 的匹配顺序（导致取错行）

- **位置**：约 7586–7639 行。
- **过程**：
  1. 若传入 `rowIndex`，先校验该行 id_product 是否匹配，匹配则直接返回该行。
  2. **未传 rowIndex 时**（getRowLabelFromProcessValue 调用时就是未传）：
     - 先做 **精确匹配**：`row[1].value === processValueResolved`。
     - 若表中该列为 `"ALLBET95MS (KM) MYR"`（带空格），而 process 为 `"ALLBET95MS(KM)MYR"`，精确匹配失败。
     - 再做 **归一化匹配**：`normalizeIdProductText(rowValue) === normalizeIdProductText(processValue)`，两者都是 `"ALLBET95MS"`，会匹配。
     - **关键**：循环是 `for (let i = 0; i < tableData.rows.length; i++)`，**第一次**归一化匹配成功就 **return 该行**。
- 表顺序若是：  
  - 行 0 = A = ALLBET95MS(SV)MYR 或 "ALLBET95MS (SV) MYR"  
  - 行 1 = B = ALLBET95MS(KM)MYR 或 "ALLBET95MS (KM) MYR"  
  - 行 2 = C = ALLBET95MS(SEXY)MYR  
则归一化后都是 "ALLBET95MS"，**先被匹配到的是行 0（A）**，于是：
  - `findProcessRow(parsedTableData, "ALLBET95MS(KM)MYR")` 返回 **A 行**；
  - `getRowLabelFromProcessValue("ALLBET95MS(KM)MYR")` 得到 **currentRowLabel = "A"**。

---

## 4. 为何会判成「其他行」并插入 [id,6]

- 用户编辑的是 **ALLBET95MS(KM)MYR)**（对应 Data Capture 的 **B 行**），在 Data 下拉或 formula data grid 里选的是 **B 行**的 [6] 3318.03。
- 因此：
  - `clickedRowLabel` = "B"（点击的格子所在行）；
  - `currentRowLabel` = "A"（由上，findProcessRow 错返回了 A 行）。
- 在 `insertCellValueToFormula` 里：
  - `rowLabelMatches` = (currentRowLabel === clickedRowLabel) = ("A" === "B") = **false**；
  - `isCurrentRow` = idProductMatches && rowLabelMatches = true && false = **false**；
  - 于是走「其他行」分支，插入 `[idProduct, displayColumnIndex]`，即 `[ALLBET95MS(KM)MYR,6]`。

---

## 5. 为何精确匹配会失败（表里带空格）

- 若 Data Capture 表或 `transformedTableData` 里 id_product 存的是 **带空格的** `"ALLBET95MS (KM) MYR"`，而 `#process` 是 **不带空格的** `"ALLBET95MS(KM)MYR"`：
  - 精确匹配 `rowValue === processValueResolved` 不成立；
  - 只能依赖归一化匹配；
  - 归一化匹配又只取「第一个」匹配行，在多行同 base（SV/KM/SEXY）时就会落到 A 行。

---

## 6. 小结（根因链）

| 环节 | 结果 |
|------|------|
| 表数据多行同 base（A=SV, B=KM, C=SEXY）且 id_product 可能带空格 | 精确匹配常失败 |
| findProcessRow 归一化匹配时返回「第一个」匹配行 | 常返回 A 行 |
| getRowLabelFromProcessValue 用该行取 row label | currentRowLabel = "A" |
| 用户选的是 B 行格子 | clickedRowLabel = "B" |
| currentRowLabel !== clickedRowLabel | rowLabelMatches = false → isCurrentRow = false |
| insertCellValueToFormula 走「其他行」 | 插入 `[ALLBET95MS(KM)MYR,6]` 而非 `$6` |

**根因**：在「同 base 多行 + 表里 id 带空格」的情况下，**findProcessRow 未传 rowIndex 时用归一化匹配且只取第一个匹配行**，导致 currentRowLabel 变成 A，与用户实际编辑/选中的 B 行不一致，从而把本行引用误判为「其他行」并写成 `[id_product, 6]`。

---

## 7. 可选修复方向（仅列出，未改代码）

1. **getRowLabelFromProcessValue / findProcessRow**  
   - 在「完整 id」（如 ALLBET95MS(KM)MYR）场景下，优先用「去空格比较」或「完整 id 精确匹配」再匹配行，且若有多个归一化匹配行，应优先选与 `processValue` 在「同一行」的那一行（例如用 id_product 去空格后与 processValue 去空格后相等），而不是总是返回第一个。
2. **insertCellValueToFormula**  
   - 当 `currentRowLabel` 与 `clickedRowLabel` 不一致时，若 **id_product 与 currentIdProduct 在「去空格/归一化」下一致**，可视为同一产品行，再结合「仅有一行匹配」或「当前编辑行就是该 id」等条件，仍判为当前行并使用 `$列号`。
3. **数据源**  
   - 保证表数据里 id_product 与 `#process` 格式一致（如统一去空格），可减少精确匹配失败，从而减少误取第一行的情况。

以上为「只分析、不改代码」的结论与可选修复方向。
