// sw.js

self.addEventListener('install', (e) => {
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  return self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  e.respondWith(fetch(e.request));
});

// ==========================================
// MANEJO DE EVENTOS PUSH Y NOTIFICATIONCLICK
// ==========================================

// Escucha cuando llega una notificación del servidor
self.addEventListener('push', (e) => {
  let data = {
    title: 'Nueva notificación',
    body: 'Tienes un nuevo mensaje.',
    icon: '/icon.png',
    badge: '/badge.png',
    url: '/'
  };

  if (e.data) {
    try {
      // Intentar parsear el payload como JSON si el servidor lo envía así
      data = Object.assign({}, data, e.data.json());
    } catch (err) {
      // Si llega como texto simple
      data.body = e.data.text();
    }
  }

  const options = {
    body: data.body,
    icon: data.icon,
    badge: data.badge,
    data: {
      url: data.url || '/' // Guardamos la URL de destino dentro de los datos de la notificación
    },
    vibrate: [100, 50, 100],
    actions: data.actions || [] // Opciones adicionales de botones si las incluyes
  };

  e.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

// Escucha cuando el usuario hace clic en la notificación
self.addEventListener('notificationclick', (e) => {
  const notification = e.notification;
  const targetUrl = notification.data ? notification.data.url : '/';

  notification.close(); // Cierra la notificación visualmente

  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      // Si la app ya está abierta en alguna pestaña, enfocarla y navegar a la URL
      for (let client of windowClients) {
        if (client.url === targetUrl && 'focus' in client) {
          return client.focus();
        }
      }
      // Si no hay pestañas abiertas con la app, abrir una nueva ventana/pestaña
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});