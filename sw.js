self.addEventListener("install", event => {
  console.log("Service Worker instalado");
});

self.addEventListener("activate", event => {
  console.log("Service Worker activo");
});

self.addEventListener("push", e => {
  const data = e.data.json();

  self.registration.showNotification(data.title, {
    body: data.body,
    icon: "/img/Logo.png",
    badge: "/img/Logo.png",
    data: { url: data.url }
  });
});

self.addEventListener("notificationclick", e => {
  e.notification.close();
  clients.openWindow(e.notification.data.url);
});
