import { useCallback, useEffect, useRef, useState } from "react";
import {
  loadStoredRemoveWordChips,
  mergeRemoveWordChips,
  parseRemoveWordChips,
  saveStoredRemoveWordChips,
  serializeRemoveWordChips,
} from "../lib/removeWordChips.js";

function normalizeDraft(value) {
  return String(value ?? "").toUpperCase();
}

export default function RemoveWordChipInput({
  value,
  onChange,
  processId = null,
  scopeCompanyId = null,
  id = "capture_remove_word",
  name = "remove_word",
  placeholder = "",
  removeChipAriaLabel = "Remove",
  disabled = false,
}) {
  const [draft, setDraft] = useState("");
  const inputRef = useRef(null);
  const chips = parseRemoveWordChips(value);

  const commitChips = useCallback(
    (nextChips) => {
      if (disabled) return;
      const serialized = serializeRemoveWordChips(nextChips);
      onChange?.(serialized);
      if (processId) {
        saveStoredRemoveWordChips(scopeCompanyId, processId, nextChips);
      }
    },
    [disabled, onChange, processId, scopeCompanyId],
  );

  useEffect(() => {
    if (!processId || disabled) return;
    const fromValue = parseRemoveWordChips(value);
    const stored = loadStoredRemoveWordChips(scopeCompanyId, processId);
    const merged = mergeRemoveWordChips(fromValue, stored);
    const serialized = serializeRemoveWordChips(merged);
    if (serialized !== serializeRemoveWordChips(fromValue)) {
      onChange?.(serialized);
    }
    if (merged.length) {
      saveStoredRemoveWordChips(scopeCompanyId, processId, merged);
    }
  }, [processId, scopeCompanyId, value, onChange, disabled]);

  const addDraftWord = useCallback(() => {
    if (disabled) return;
    const word = normalizeDraft(draft.trim());
    if (!word) return;
    const exists = chips.some((chip) => chip.toLowerCase() === word.toLowerCase());
    if (exists) {
      setDraft("");
      return;
    }
    commitChips([...chips, word]);
    setDraft("");
  }, [chips, commitChips, disabled, draft]);

  const removeChip = useCallback(
    (index) => {
      if (disabled) return;
      commitChips(chips.filter((_, i) => i !== index));
    },
    [chips, commitChips, disabled],
  );

  const handleContainerClick = () => {
    if (disabled) return;
    inputRef.current?.focus();
  };

  const handleKeyDown = (event) => {
    if (disabled) return;
    if (event.key === "Enter") {
      event.preventDefault();
      addDraftWord();
      return;
    }
    if (event.key === ";" || event.key === ",") {
      event.preventDefault();
      addDraftWord();
      return;
    }
    if (event.key === "Backspace" && draft === "" && chips.length > 0) {
      event.preventDefault();
      commitChips(chips.slice(0, -1));
    }
  };

  const inputStyle =
    chips.length === 0
      ? { flex: "1 1 0", minWidth: "4ch" }
      : { flex: "0 1 auto", width: `${Math.max(draft.length + 1, 4)}ch` };

  return (
    <div
      className={`dc-remove-word-chip-input${disabled ? " is-disabled" : ""}`}
      onClick={handleContainerClick}
    >
      {chips.map((chip, index) => (
        <span key={`${chip}-${index}`} className="dc-remove-word-chip">
          <span className="dc-remove-word-chip__label">{chip}</span>
          {!disabled ? (
            <button
              type="button"
              className="dc-remove-word-chip__remove"
              aria-label={`${removeChipAriaLabel} ${chip}`}
              onClick={(event) => {
                event.stopPropagation();
                removeChip(index);
              }}
            >
              ×
            </button>
          ) : null}
        </span>
      ))}
      {!disabled ? (
        <input
          ref={inputRef}
          type="text"
          id={id}
          name={name}
          className="dc-remove-word-chip-input__field"
          value={draft}
          placeholder={chips.length ? "" : placeholder}
          style={inputStyle}
          onChange={(event) => setDraft(normalizeDraft(event.target.value))}
          onKeyDown={handleKeyDown}
          autoComplete="off"
        />
      ) : null}
    </div>
  );
}
