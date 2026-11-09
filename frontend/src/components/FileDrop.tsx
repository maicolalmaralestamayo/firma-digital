import { useRef, useState } from 'react';
import type { ChangeEvent, DragEvent } from 'react';
import { formatBytes } from '../lib/format';

interface FileDropProps {
  label: string;
  hint?: string;
  accept?: string;
  file: File | null;
  onFile: (file: File | null) => void;
  disabled?: boolean;
  required?: boolean;
}

export function FileDrop({ label, hint, accept, file, onFile, disabled = false, required = false }: FileDropProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);

  const pick = (event: ChangeEvent<HTMLInputElement>) => {
    onFile(event.target.files?.[0] ?? null);
  };

  const drop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    setDragging(false);

    if (disabled) {
      return;
    }

    onFile(event.dataTransfer.files?.[0] ?? null);
  };

  return (
    <div className="field">
      <label className="field__label">
        {label}
        {required && <span className="required"> *</span>}
      </label>
      <div
        className={`dropzone${dragging ? ' dropzone--active' : ''}${file ? ' dropzone--filled' : ''}${
          disabled ? ' dropzone--disabled' : ''
        }`}
        onDragOver={(event) => {
          event.preventDefault();
          if (!disabled) {
            setDragging(true);
          }
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={drop}
        onClick={() => {
          if (!disabled) {
            inputRef.current?.click();
          }
        }}
        role="button"
        tabIndex={0}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            inputRef.current?.click();
          }
        }}
      >
        <input
          ref={inputRef}
          type="file"
          accept={accept}
          onChange={pick}
          hidden
          disabled={disabled}
        />
        {file ? (
          <div className="dropzone__file">
            <span aria-hidden="true">📎</span>
            <div>
              <strong>{file.name}</strong>
              <small>{formatBytes(file.size)}</small>
            </div>
            <button
              type="button"
              className="btn btn--link"
              onClick={(event) => {
                event.stopPropagation();
                onFile(null);
                if (inputRef.current) {
                  inputRef.current.value = '';
                }
              }}
            >
              Quitar
            </button>
          </div>
        ) : (
          <div className="dropzone__empty">
            <span aria-hidden="true">⬆</span>
            <div>
              <strong>Arrastra el archivo o haz clic para elegirlo</strong>
              {hint && <small>{hint}</small>}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
