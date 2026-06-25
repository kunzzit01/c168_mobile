import { useCallback, useEffect, useRef, useState } from "react";

/**
 * Keyboard navigation for custom listbox dropdowns (ArrowUp/Down, Enter, Escape).
 * Works with searchable dropdowns (search input) or button-only dropdowns.
 */
export function useListboxKeyboard({ open, itemCount, resetToken = null, initialIndex = 0 }) {
  const [highlightIdx, setHighlightIdx] = useState(initialIndex);
  const listRef = useRef(null);

  useEffect(() => {
    if (!open) setHighlightIdx(initialIndex);
  }, [open, initialIndex]);

  useEffect(() => {
    if (open) setHighlightIdx(initialIndex);
  }, [resetToken, initialIndex, open]);

  useEffect(() => {
    if (!open || highlightIdx < 0 || !listRef.current) return;
    const node = listRef.current.querySelector(`[data-kb-idx="${highlightIdx}"]`);
    node?.scrollIntoView({ block: "nearest" });
  }, [highlightIdx, open, itemCount]);

  const moveDown = useCallback(
    (len) => {
      if (len <= 0) return;
      setHighlightIdx((hi) => (hi < 0 ? 0 : (hi + 1) % len));
    },
    [],
  );

  const moveUp = useCallback(
    (len) => {
      if (len <= 0) return;
      setHighlightIdx((hi) => (hi <= 0 ? len - 1 : hi - 1));
    },
    [],
  );

  const handleListKeyDown = useCallback(
    (e, { len, onSelectIndex, onClose }) => {
      const count = len ?? itemCount;
      if (e.key === "Escape") {
        e.preventDefault();
        onClose?.();
        return;
      }
      if (count <= 0) return;
      if (e.key === "ArrowDown") {
        e.preventDefault();
        moveDown(count);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        moveUp(count);
      } else if (e.key === "Enter") {
        e.preventDefault();
        const idx = highlightIdx >= 0 ? highlightIdx : 0;
        onSelectIndex?.(idx);
      }
    },
    [highlightIdx, itemCount, moveDown, moveUp],
  );

  const handleButtonKeyDown = useCallback(
    (e, { isOpen, onToggleOpen, onClose, len, onSelectIndex }) => {
      const count = len ?? itemCount;
      if (e.key === "Escape" && isOpen) {
        e.preventDefault();
        onClose?.();
        return;
      }
      if (!isOpen) {
        if (e.key === "ArrowDown" || e.key === "ArrowUp" || e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          onToggleOpen?.();
        }
        return;
      }
      handleListKeyDown(e, { len: count, onSelectIndex, onClose });
    },
    [handleListKeyDown, itemCount],
  );

  const highlightClass = (idx) => (highlightIdx === idx && highlightIdx >= 0 ? " keyboard-focus" : "");

  return {
    highlightIdx,
    setHighlightIdx,
    listRef,
    handleListKeyDown,
    handleButtonKeyDown,
    highlightClass,
  };
}
