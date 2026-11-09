import { useCallback, useEffect, useRef, useState } from 'react';
import * as pdfjsLib from 'pdfjs-dist';
import type { PDFDocumentProxy } from 'pdfjs-dist';
import workerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import type { Placement } from '../lib/types';

pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;

const MIN_WIDTH_PT = 120;
const MIN_HEIGHT_PT = 45;

interface PdfPlacementProps {
  file: Blob | File | null;
  placement: Placement;
  onPlacementChange: (placement: Placement) => void;
  page: number;
  onPageChange: (page: number) => void;
  previewImage?: string | null;
  disabled?: boolean;
}

interface PageSize {
  width: number;
  height: number;
}

interface DragState {
  mode: 'move' | 'resize';
  pointerId: number;
  startX: number;
  startY: number;
  origin: Placement;
}

const clamp = (value: number, min: number, max: number): number => Math.min(Math.max(value, min), max);
const round = (value: number): number => Math.round(value * 10) / 10;

export function PdfPlacement({
  file,
  placement,
  onPlacementChange,
  page,
  onPageChange,
  previewImage,
  disabled = false,
}: PdfPlacementProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const dragRef = useRef<DragState | null>(null);

  const [pdf, setPdf] = useState<PDFDocumentProxy | null>(null);
  const [numPages, setNumPages] = useState(0);
  const [scale, setScale] = useState(1);
  const [pageSize, setPageSize] = useState<PageSize>({ width: 595, height: 842 });
  const [containerWidth, setContainerWidth] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const element = containerRef.current;

    if (!element) {
      return;
    }

    const observer = new ResizeObserver((entries) => {
      setContainerWidth(entries[0]?.contentRect.width ?? 0);
    });

    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    let cancelled = false;
    let loadingTask: pdfjsLib.PDFDocumentLoadingTask | null = null;

    if (!file) {
      setPdf(null);
      setNumPages(0);
      return;
    }

    setLoading(true);
    setError(null);

    (async () => {
      try {
        const data = new Uint8Array(await file.arrayBuffer());
        loadingTask = pdfjsLib.getDocument({ data });
        const loaded = await loadingTask.promise;

        if (cancelled) {
          void loadingTask.destroy();
          return;
        }

        setPdf(loaded);
        setNumPages(loaded.numPages);

        if (page > loaded.numPages) {
          onPageChange(1);
        }
      } catch {
        if (!cancelled) {
          setError('No se pudo leer el PDF. Verifica que no esté dañado ni protegido con contraseña.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
      if (loadingTask) {
        void loadingTask.destroy();
      }
    };
    // `page` and `onPageChange` intentionally excluded: the document loads once per file.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [file]);

  useEffect(() => {
    let cancelled = false;
    let task: { cancel: () => void } | null = null;

    if (!pdf) {
      return;
    }

    (async () => {
      const current = await pdf.getPage(clamp(page, 1, pdf.numPages));
      const base = current.getViewport({ scale: 1 });
      const available = containerWidth > 0 ? containerWidth : base.width;
      const nextScale = clamp(available / base.width, 0.2, 2.5);
      const viewport = current.getViewport({ scale: nextScale });

      if (cancelled) {
        return;
      }

      setScale(nextScale);
      setPageSize({ width: viewport.width / nextScale, height: viewport.height / nextScale });

      const canvas = canvasRef.current;

      if (!canvas) {
        return;
      }

      const ratio = window.devicePixelRatio || 1;
      canvas.width = Math.floor(viewport.width * ratio);
      canvas.height = Math.floor(viewport.height * ratio);
      canvas.style.width = `${viewport.width}px`;
      canvas.style.height = `${viewport.height}px`;

      const context = canvas.getContext('2d');

      if (!context) {
        return;
      }

      context.setTransform(ratio, 0, 0, ratio, 0, 0);
      const render = current.render({ canvas, canvasContext: context, viewport } as never);
      task = render;

      try {
        await render.promise;
      } catch {
        // Rendering was cancelled by a page or zoom change.
      }
    })();

    return () => {
      cancelled = true;
      task?.cancel();
    };
  }, [pdf, page, containerWidth]);

  // Keep the stamp inside the page whenever the page or size changes.
  useEffect(() => {
    if (!pdf) {
      return;
    }

    const width = Math.min(placement.width, pageSize.width);
    const height = Math.min(placement.height, pageSize.height);
    const x = clamp(placement.x, 0, Math.max(0, pageSize.width - width));
    const y = clamp(placement.y, 0, Math.max(0, pageSize.height - height));

    if (
      width !== placement.width ||
      height !== placement.height ||
      x !== placement.x ||
      y !== placement.y ||
      placement.page !== page
    ) {
      onPlacementChange({ page, x: round(x), y: round(y), width: round(width), height: round(height) });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageSize.width, pageSize.height, page]);

  const start = useCallback(
    (mode: 'move' | 'resize') => (event: React.PointerEvent<HTMLDivElement>) => {
      if (disabled) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);

      dragRef.current = {
        mode,
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        origin: { ...placement },
      };
    },
    [disabled, placement],
  );

  const move = (event: React.PointerEvent<HTMLDivElement>) => {
    const drag = dragRef.current;

    if (!drag || drag.pointerId !== event.pointerId || disabled) {
      return;
    }

    const deltaX = (event.clientX - drag.startX) / scale;
    const deltaY = (event.clientY - drag.startY) / scale;

    if (drag.mode === 'move') {
      onPlacementChange({
        page,
        x: round(clamp(drag.origin.x + deltaX, 0, Math.max(0, pageSize.width - placement.width))),
        y: round(clamp(drag.origin.y + deltaY, 0, Math.max(0, pageSize.height - placement.height))),
        width: placement.width,
        height: placement.height,
      });

      return;
    }

    onPlacementChange({
      page,
      x: placement.x,
      y: placement.y,
      width: round(
        clamp(drag.origin.width + deltaX, MIN_WIDTH_PT, Math.max(MIN_WIDTH_PT, pageSize.width - placement.x)),
      ),
      height: round(
        clamp(drag.origin.height + deltaY, MIN_HEIGHT_PT, Math.max(MIN_HEIGHT_PT, pageSize.height - placement.y)),
      ),
    });
  };

  const finish = (event: React.PointerEvent<HTMLDivElement>) => {
    if (dragRef.current?.pointerId !== event.pointerId) {
      return;
    }

    dragRef.current = null;

    try {
      (event.currentTarget as HTMLElement).releasePointerCapture(event.pointerId);
    } catch {
      // The pointer was already released.
    }
  };

  if (!file) {
    return (
      <div className="pdf-empty">
        <span aria-hidden="true">📄</span>
        <p>Sube un PDF para verlo y elegir dónde se estampa la firma.</p>
      </div>
    );
  }

  return (
    <div className="pdf-placement">
      <div className="pdf-toolbar">
        <button
          type="button"
          className="btn btn--ghost btn--sm"
          onClick={() => onPageChange(Math.max(1, page - 1))}
          disabled={page <= 1}
        >
          ← Anterior
        </button>
        <span className="pdf-toolbar__page">
          Página <strong>{page}</strong> de {numPages || '—'}
        </span>
        <button
          type="button"
          className="btn btn--ghost btn--sm"
          onClick={() => onPageChange(Math.min(numPages, page + 1))}
          disabled={numPages === 0 || page >= numPages}
        >
          Siguiente →
        </button>
      </div>

      <div className="pdf-canvas-wrap" ref={containerRef}>
        {loading && (
          <div className="pdf-overlay">
            <span className="spinner" aria-hidden="true" /> Cargando documento…
          </div>
        )}
        {error && <div className="alert alert--danger">{error}</div>}

        <div
          className="pdf-page"
          style={{ width: pageSize.width * scale, height: pageSize.height * scale }}
        >
          <canvas ref={canvasRef} />

          <div
            className={`stamp-box${disabled ? ' stamp-box--disabled' : ''}`}
            style={{
              left: placement.x * scale,
              top: placement.y * scale,
              width: placement.width * scale,
              height: placement.height * scale,
            }}
            onPointerDown={start('move')}
            onPointerMove={move}
            onPointerUp={finish}
            onPointerCancel={finish}
            role="presentation"
          >
            {previewImage && <img src={previewImage} alt="" className="stamp-box__image" />}
            <div className="stamp-box__text">
              <strong>Firma aquí</strong>
              <span>
                {Math.round(placement.width)} × {Math.round(placement.height)} pt
              </span>
            </div>
            {!disabled && (
              <div
                className="stamp-box__handle"
                onPointerDown={start('resize')}
                onPointerMove={move}
                onPointerUp={finish}
                onPointerCancel={finish}
                role="presentation"
                title="Arrastra para cambiar el tamaño"
              />
            )}
          </div>
        </div>
      </div>

      <p className="hint">
        Arrastra el recuadro para ubicarlo y usa la esquina inferior derecha para cambiar su tamaño. La imagen
        y los datos del certificado se estampan dentro de ese recuadro.
      </p>
    </div>
  );
}
