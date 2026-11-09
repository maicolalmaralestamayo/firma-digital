import { useEffect, useState } from 'react';
import { FileDrop } from './FileDrop';

interface StampImageFieldProps {
  image: File | null;
  onImage: (file: File | null) => void;
  disabled?: boolean;
}

export function StampImageField({ image, onImage, disabled = false }: StampImageFieldProps) {
  const [preview, setPreview] = useState<string | null>(null);

  useEffect(() => {
    if (!image) {
      setPreview(null);
      return;
    }

    const url = URL.createObjectURL(image);
    setPreview(url);

    return () => URL.revokeObjectURL(url);
  }, [image]);

  return (
    <div className="stack">
      <FileDrop
        label="Imagen de la firma"
        hint="PNG o JPEG: firma manuscrita, sello o logo"
        accept="image/png,image/jpeg"
        file={image}
        onFile={onImage}
        disabled={disabled}
        required
      />

      <div className="stamp-preview">
        {preview ? (
          <img src={preview} alt="Vista previa de la imagen de firma" />
        ) : (
          <span>La vista previa de la imagen aparecerá aquí.</span>
        )}
      </div>
    </div>
  );
}
