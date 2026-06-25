/** Align TOTAL rows with PHP paste behavior — drop empty gap column(s) before numeric totals. */

function trimCellValue(cell) {
  if (cell != null && typeof cell === "object" && "value" in cell) {
    return String(cell.value ?? "").trim();
  }
  return String(cell ?? "").trim();
}

function isBlankCell(value) {
  return String(value ?? "")
    .replace(/\u00a0/g, " ")
    .trim() === "";
}

function isNumericSerial(value) {
  return /^\d+$/.test(value) && value.length <= 6;
}

function isAlphaCode(value) {
  return /^[A-Za-z]{2,8}\d*$/.test(value);
}

function isNameLike(value) {
  if (isBlankCell(value)) return false;
  const cleaned = String(value).replace(/,/g, "");
  if (/^-?\d+(\.\d+)?$/.test(cleaned)) return false;
  return true;
}

function isTotalLabel(value) {
  const upper = String(value || "").trim().toUpperCase();
  return upper === "TOTAL" || upper === "SUB TOTAL" || upper === "GRAND TOTAL";
}

function rowHasTotalLabel(row) {
  if (!Array.isArray(row)) return false;
  for (let i = 0; i < Math.min(row.length, 4); i += 1) {
    if (isTotalLabel(trimCellValue(row[i]))) return true;
  }
  return false;
}

function cloneTailCell(row) {
  const tail = row[row.length - 1];
  if (tail != null && typeof tail === "object" && "value" in tail) {
    return { ...tail, value: "" };
  }
  return "";
}

/** True when regular rows use serial | code | name before numeric columns. */
export function matrixHasNameColumnPattern(matrix) {
  if (!Array.isArray(matrix) || matrix.length < 2) return false;

  let matches = 0;
  for (const row of matrix) {
    if (!Array.isArray(row) || row.length < 3) continue;

    const col0 = trimCellValue(row[0]);
    const col1 = trimCellValue(row[1]);
    const col2 = trimCellValue(row[2]);

    if (rowHasTotalLabel(row)) continue;
    if (isNumericSerial(col0) && isAlphaCode(col1) && isNameLike(col2)) {
      matches += 1;
      if (matches >= 1) return true;
    }
  }

  return false;
}

function findTotalLabelIndex(row) {
  for (let i = 0; i < Math.min(row.length, 4); i += 1) {
    if (isTotalLabel(trimCellValue(row[i]))) return i;
  }
  return -1;
}

/**
 * Remove consecutive blank cells between TOTAL label and the first data value.
 * Matches PHP: TOTAL row skips the empty name column so totals start one column earlier.
 */
export function alignTotalRowArray(row) {
  if (!Array.isArray(row) || row.length < 3) return row;

  const totalIndex = findTotalLabelIndex(row);
  if (totalIndex < 0) return row;

  let firstDataIndex = -1;
  for (let i = totalIndex + 1; i < row.length; i += 1) {
    const value = trimCellValue(row[i]);
    if (!isBlankCell(value) && !isTotalLabel(value)) {
      firstDataIndex = i;
      break;
    }
  }

  if (firstDataIndex <= totalIndex + 1) return row;

  const next = [...row];
  let removed = 0;
  while (totalIndex + 1 < firstDataIndex - removed && isBlankCell(trimCellValue(next[totalIndex + 1]))) {
    next.splice(totalIndex + 1, 1);
    removed += 1;
  }

  if (removed === 0) return row;

  while (next.length < row.length) {
    next.push(cloneTailCell(row));
  }

  return next;
}

/**
 * @param {Array<Array<string|object>>} matrix
 * @returns {Array<Array<string|object>>}
 */
export function alignTotalRowsInMatrix(matrix) {
  if (!Array.isArray(matrix) || !matrix.length) return matrix;
  if (!matrixHasNameColumnPattern(matrix) && !matrix.some(rowHasTotalLabel)) return matrix;

  let changed = false;
  const aligned = matrix.map((row) => {
    const next = alignTotalRowArray(row);
    if (next !== row) changed = true;
    return next;
  });

  if (changed) {
    console.log("Aligned TOTAL row columns to match PHP (removed empty gap before totals).");
  }

  return aligned;
}

function getSnapshotDataText(rowData, dataColIndex) {
  const cell = rowData[dataColIndex + 1];
  if (!cell || cell.type !== "data") return "";
  return String(cell.value || "").trim();
}

export function alignSnapshotRow(rowData) {
  if (!Array.isArray(rowData) || rowData.length < 4) return rowData;

  const values = [];
  for (let i = 0; i < rowData.length - 1; i += 1) {
    values.push(getSnapshotDataText(rowData, i));
  }

  const alignedValues = alignTotalRowArray(values);
  if (alignedValues === values) return rowData;

  const next = [rowData[0]];
  for (let i = 0; i < alignedValues.length; i += 1) {
    const prev = rowData[i + 1];
    const value = alignedValues[i];
    if (prev?.type === "data") {
      next.push({ ...prev, value, col: i });
    } else {
      next.push({ type: "data", value, col: i });
    }
  }

  return next;
}

/**
 * Submit-time snapshot fix (same rule as paste matrix alignment).
 * @param {object} tableData
 * @returns {object}
 */
export function alignTotalRowsInSnapshot(tableData) {
  if (!tableData?.rows?.length) return tableData;

  const probe = tableData.rows.map((rowData) => {
    const values = [];
    for (let i = 0; i < Math.max(0, (rowData?.length || 1) - 1); i += 1) {
      values.push(getSnapshotDataText(rowData, i));
    }
    return values;
  });

  if (!matrixHasNameColumnPattern(probe) && !probe.some(rowHasTotalLabel)) return tableData;

  const working = JSON.parse(JSON.stringify(tableData));
  let changed = false;

  working.rows = working.rows.map((rowData) => {
    const aligned = alignSnapshotRow(rowData);
    if (aligned !== rowData) changed = true;
    return aligned;
  });

  if (!changed) return tableData;
  console.log("Submit snapshot: aligned TOTAL row columns to match PHP.");
  return working;
}
