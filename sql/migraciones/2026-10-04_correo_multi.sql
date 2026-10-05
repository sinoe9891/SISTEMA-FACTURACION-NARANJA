-- «Responder a» y «copia oculta» admiten varios correos separados por coma.
ALTER TABLE configuracion_correo MODIFY responder_a VARCHAR(300) NULL, MODIFY copia_oculta VARCHAR(300) NULL;
